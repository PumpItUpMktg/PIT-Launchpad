<?php

use App\ContentEngine\Drafting\DraftCall;
use App\ContentEngine\Drafting\Drafter;
use App\ContentEngine\Drafting\DraftRequest;
use App\ContentEngine\Drafting\GroundingAssembler;
use App\ContentEngine\Drafting\PageDrafter;
use App\ContentEngine\Drafting\PageGroundingAssembler;
use App\Enums\ContentKind;
use App\Enums\ContentStatus;
use App\Enums\IndexCoverageState;
use App\Enums\PageType;
use App\Enums\UserRole;
use App\Filament\Pages\IndexingBoard;
use App\Jobs\GeneratePage;
use App\Jobs\GeneratePost;
use App\Jobs\PublishRedirects;
use App\Models\Content;
use App\Models\Location;
use App\Models\PageIndexState;
use App\Models\Redirect;
use App\Models\Silo;
use App\Models\Site;
use App\Models\User;
use App\Operator\ActiveTenant;
use App\Operator\Coverage\IndexRework;
use App\Operator\Coverage\ReworkCandidates;
use Filament\Facades\Filament;
use Illuminate\Support\Facades\Queue;
use Livewire\Livewire;
use Tests\Support\FakeClaudeClient;
use Tests\Support\PublishHarness;

/** A crawled-and-declined verdict on a page, inspected, published $days ago. */
function crawledDeclined(Site $site, Content $page, int $days): void
{
    $page->forceFill(['published_at' => now()->subDays($days)])->save();
    PageIndexState::create([
        'site_id' => $site->id, 'content_id' => $page->id, 'url' => "https://rw.example/{$page->slug}/", 'url_normalized' => "https://rw.example/{$page->slug}",
        'coverage_state' => IndexCoverageState::CrawledNotIndexed->value, 'index_verdict' => IndexCoverageState::CrawledNotIndexed->value, 'last_inspected_at' => now()->subDay(),
    ]);
}

function reworkSite(): array
{
    $site = Site::factory()->create(['brand_name' => 'RW', 'domain_url' => 'https://rw.example']);
    $office = Location::factory()->create(['site_id' => $site->id, 'name' => 'Hackettstown office', 'served_towns' => []]);
    $pumps = Silo::factory()->create(['site_id' => $site->id, 'name' => 'Sump Pumps']);
    $town = Content::factory()->create([
        'site_id' => $site->id, 'kind' => ContentKind::Page, 'page_type' => PageType::Location, 'status' => ContentStatus::Published,
        'location_id' => null, 'parent_location_id' => $office->id, 'title' => 'Allamuchy, NJ', 'slug' => 'allamuchy-nj', 'wp_post_id' => 1,
        'meta' => ['priority_sections' => [['keyword_id' => 'k1', 'keyword' => 'backup sump pump', 'heading' => 'Backup sump pump installation in Allamuchy', 'body' => 'Keep this.', 'faqs' => []]]],
    ]);
    $post = fn (string $title, string $slug, ?Silo $silo, array $extra = []) => Content::factory()->post()->create(array_merge([
        'site_id' => $site->id, 'silo_id' => $silo?->id, 'matched_silo_id' => $silo?->id, 'status' => ContentStatus::Published, 'title' => $title, 'slug' => $slug, 'wp_post_id' => null,
    ], $extra));
    $strong = $post('Sump Pump Maintenance Checklist for Homeowners', 'maintenance-checklist', $pumps, ['wp_post_id' => 5]);
    $dup = $post('Sump Pump Maintenance Checklist Before Spring', 'maintenance-spring', $pumps);
    $offTopic = $post('Best Home Warranty Companies in Pennsylvania', 'home-warranty', null);
    $thin = $post('Where Should Sump Pump Water Go', 'where-water-goes', $pumps);
    $fresh = $post('Sump Pump Check Valve Basics', 'check-valve', $pumps);
    crawledDeclined($site, $town, 45);
    crawledDeclined($site, $dup, 40);
    crawledDeclined($site, $offTopic, 35);
    crawledDeclined($site, $thin, 31);
    crawledDeclined($site, $fresh, 10);   // too young for the rework window

    return compact('site', 'office', 'pumps', 'town', 'strong', 'dup', 'offTopic', 'thin', 'fresh');
}

it('decides three ways for crawled-and-declined pages past the window — a town page is always reworked, never pruned', function () {
    $f = reworkSite();

    $report = app(ReworkCandidates::class)->for($f['site']);
    $byTitle = collect($report['rows'])->keyBy('title');

    expect($report['rework_days'])->toBe(30)
        ->and($byTitle->keys()->all())->not->toContain('Sump Pump Check Valve Basics')   // 10 days: not yet
        ->and($byTitle['Allamuchy, NJ']['verdict'])->toBe(ReworkCandidates::THIN)
        ->and($byTitle['Allamuchy, NJ']['is_town'])->toBeTrue()
        ->and($byTitle['Allamuchy, NJ']['reason'])->toContain('never pruned')
        ->and($byTitle['Sump Pump Maintenance Checklist Before Spring']['verdict'])->toBe(ReworkCandidates::DUPLICATE)
        ->and($byTitle['Sump Pump Maintenance Checklist Before Spring']['duplicate_of']['title'])->toBe('Sump Pump Maintenance Checklist for Homeowners')
        ->and($byTitle['Best Home Warranty Companies in Pennsylvania']['verdict'])->toBe(ReworkCandidates::OFF_TOPIC)
        ->and($byTitle['Where Should Sump Pump Water Go']['verdict'])->toBe(ReworkCandidates::THIN)
        ->and($report['by_verdict'])->toBe(['duplicate' => 1, 'off_topic' => 1, 'thin' => 2]);
});

it('apply stores the index brief and regenerates the thin ones through their own flow; a duplicate or off-topic post is never reworked here', function () {
    Queue::fake();
    $f = reworkSite();

    $result = app(ReworkCandidates::class)->apply($f['site'], [$f['town']->id, $f['thin']->id, $f['dup']->id, $f['offTopic']->id]);

    expect($result['queued'])->toBe(2)->and($result['skipped'])->toBe([$f['dup']->id, $f['offTopic']->id])
        ->and(IndexRework::pending($f['town']->fresh()))->toContain('true of this town specifically')
        ->and(IndexRework::pending($f['thin']->fresh()))->toContain('most specific, useful page');
    Queue::assertPushed(GeneratePage::class, fn (GeneratePage $j) => $j->contentId === $f['town']->id);
    Queue::assertPushed(GeneratePost::class, fn (GeneratePost $j) => $j->contentId === $f['thin']->id);
});

it('merge gives the duplicate a 301 to the stronger post and takes it down; a town page can never be merged', function () {
    Queue::fake();
    $f = reworkSite();

    $result = app(ReworkCandidates::class)->merge($f['dup'], $f['strong']);

    expect($result['redirected'])->toBeTrue()->and($result['deleted'])->toBeTrue()
        ->and(Redirect::withoutGlobalScopes()->where('site_id', $f['site']->id)->where('from_url', '/maintenance-spring/')->value('to_url'))->toBe('/maintenance-checklist/')
        ->and($f['dup']->fresh()->status)->toBe(ContentStatus::Candidate);
    Queue::assertPushed(PublishRedirects::class);

    expect(fn () => app(ReworkCandidates::class)->merge($f['town'], $f['strong']))->toThrow(InvalidArgumentException::class, 'never removed');
});

it('the brief reaches both drafters, and a redraft keeps the page\'s priority sections and stamps the rework applied', function () {
    $f = reworkSite();

    // The page drafter: the brief rides the grounding into the prompt.
    $page = PublishHarness::approvedPage($f['site']);
    IndexRework::request($page, ReworkCandidates::THIN, 'Say what the Austin water table does to tanks.');
    $fake = new FakeClaudeClient('');
    app()->instance(DraftCall::class, new DraftCall($fake));
    app(PageDrafter::class)->attempt(app(PageGroundingAssembler::class)->assemble($page->fresh()));
    expect($fake->prompts[0])->toContain('INDEX REWORK — Google crawled the previous version')->toContain('Austin water table');

    // The post drafter: the brief rides the request.
    IndexRework::request($f['thin'], ReworkCandidates::THIN, 'Name the three legal discharge options.');
    $request = DraftRequest::forCandidate($f['thin']->fresh());
    expect($request->brief['index_rework'] ?? null)->toBe('Name the three legal discharge options.');
    $fake2 = new FakeClaudeClient('');
    app()->instance(DraftCall::class, new DraftCall($fake2));
    app(Drafter::class)->attempt($request, app(GroundingAssembler::class)->assemble($request));
    expect($fake2->prompts[0])->toContain('INDEX REWORK')->toContain('three legal discharge options');

    // The carry rule: the draft's own meta is rebuilt; the page-life keys survive; the brief is stamped applied.
    $carried = IndexRework::carry($f['town']->meta + ['index_rework' => ['brief' => 'b', 'verdict' => 'thin', 'requested_at' => 'x', 'applied_at' => null], 'seo' => ['title' => 'old']], ['seo' => ['title' => 'new']]);
    expect($carried['seo']['title'])->toBe('new')
        ->and($carried['priority_sections'][0]['heading'])->toBe('Backup sump pump installation in Allamuchy')
        ->and($carried['index_rework']['applied_at'])->not->toBeNull();
});

it('the Why? panel offers Rework on a crawled town page (never Take down) and Merge on a duplicate post; the command reports both', function () {
    Filament::setCurrentPanel('admin');
    $this->actingAs(User::factory()->create(['role' => UserRole::Operator]));
    $f = reworkSite();
    app(ActiveTenant::class)->set($f['site']->id);

    $html = Livewire::test(IndexingBoard::class)->call('explain', $f['town']->id)->html();
    expect($html)->toContain('Rework this page')->toContain('never pruned')->not->toContain('Take down this post');

    $html = Livewire::test(IndexingBoard::class)->call('explain', $f['dup']->id)->html();
    expect($html)->toContain('Merge into');

    Queue::fake();   // from here the generate job must not run inline
    Livewire::test(IndexingBoard::class)->call('explain', $f['town']->id)->call('reworkPage', $f['town']->id)->assertSet('whyId', null);
    Queue::assertPushed(GeneratePage::class);

    $this->artisan('launchpad:rework-stuck', ['--site' => 'RW'])
        ->expectsOutputToContain('thin       2')
        ->expectsOutputToContain('duplicate  1')
        ->assertSuccessful();
});
