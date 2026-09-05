<?php

use App\Enums\BlogTargetStatus;
use App\Enums\ContentKind;
use App\Enums\ContentStatus;
use App\Enums\KeywordSource;
use App\Enums\RenderStatus;
use App\Enums\UserRole;
use App\Filament\Pages\Guided\Grow;
use App\Filament\Pages\Live\LiveLocations;
use App\Filament\Pages\Operate\OperateBlog;
use App\Filament\Pages\Operate\OperateCorePages;
use App\Filament\Pages\Operate\OperateLocationPages;
use App\Filament\Pages\Operate\OperateServicePages;
use App\Jobs\GeneratePost;
use App\Jobs\PublishContent;
use App\Models\BlogTarget;
use App\Models\Content;
use App\Models\Keyword;
use App\Models\Silo;
use App\Models\Site;
use App\Models\User;
use App\Operate\BlogBoard;
use App\Publishing\TenantStorage;
use Filament\Facades\Filament;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;

beforeEach(function () {
    Filament::setCurrentPanel('admin');
    $this->actingAs(User::factory()->create(['role' => UserRole::Operator]));
    config()->set('launchpad.new_operate_enabled', true);
});

function opSite(string $brand = 'SPG'): Site
{
    return Site::factory()->create(['brand_name' => $brand, 'domain_url' => 'https://'.strtolower($brand).'.example']);
}

/** A silo with its pillar page — the "page the articles provide relevance for". */
function opSilo(Site $site, string $name = 'Sump Pumps'): Silo
{
    $pillar = Content::factory()->create([
        'site_id' => $site->id, 'kind' => ContentKind::Page, 'title' => $name.' Guide', 'slug' => strtolower(str_replace(' ', '-', $name)),
    ]);
    $silo = Silo::factory()->create(['site_id' => $site->id, 'name' => $name]);
    $silo->forceFill(['pillar_content_id' => $pillar->id])->save();

    return $silo;
}

function opKeyword(Site $site, Silo $silo, string $query): Keyword
{
    return Keyword::factory()->create([
        'site_id' => $site->id, 'silo_id' => $silo->id, 'query' => $query,
        'source' => KeywordSource::Seed, 'target_content_id' => null,
    ]);
}

it('flag off ⇒ Operate hidden; on ⇒ Blog · the three pages boards (Grow/Live untouched)', function () {
    config()->set('launchpad.new_operate_enabled', false);
    expect(OperateBlog::shouldRegisterNavigation())->toBeFalse()
        ->and(OperateCorePages::shouldRegisterNavigation())->toBeFalse();

    config()->set('launchpad.new_operate_enabled', true);
    expect(OperateBlog::shouldRegisterNavigation())->toBeTrue()
        ->and(OperateBlog::getNavigationGroup())->toBe('Operate')
        // The pages boards ARE the Operate page surfaces — Core / Service / Location, full lifecycle.
        ->and(OperateCorePages::getNavigationGroup())->toBe('Operate')
        ->and(OperateServicePages::getNavigationGroup())->toBe('Operate')
        ->and(OperateLocationPages::getNavigationGroup())->toBe('Operate')
        // Grow + the old Live boards stay exactly where they were, flag on or off.
        ->and(Grow::getNavigationGroup())->toBeNull()
        ->and(LiveLocations::getNavigationGroup())->toBe('Live Pages');
});

// NOTE (tenant-lock remediation, rule 3): the former "dashboard rolls up attention across tenants and
// hides clean ones" test asserted a CROSS-TENANT render (BusyCo present, CleanCo absent) — i.e. it was
// asserting the very lock breach. It is deleted with the cross-tenant OperateDashboard + AttentionBoard.
// The cross-tenant attention function is the Lobby's; its starved-queues and live-setup-gaps coverage
// moved to LobbyBoardTest (the two new lobby badges).

it('candidates sort directed-first and carry the keyword + the page it will support', function () {
    $site = opSite();
    $silo = opSilo($site);
    $keyword = opKeyword($site, $silo, 'sump pump maintenance cost');

    $reactive = Content::factory()->create([
        'site_id' => $site->id, 'kind' => ContentKind::Post, 'status' => ContentStatus::Candidate,
        'matched_silo_id' => $silo->id, 'relevance_score' => 0.95, 'source_name' => 'Patch',
    ]);
    $directed = Content::factory()->create([
        'site_id' => $site->id, 'kind' => ContentKind::Post, 'status' => ContentStatus::Candidate,
        'matched_silo_id' => $silo->id, 'target_keyword_id' => $keyword->id, 'relevance_score' => 0.10,
    ]);

    $cards = app(BlogBoard::class)->candidates($site->id);

    expect($cards[0]['id'])->toBe($directed->id)               // directed outranks higher-scored reactive
        ->and($cards[0]['keyword'])->toBe('sump pump maintenance cost')
        ->and($cards[0]['target_page'])->toBe('Sump Pumps Guide')
        ->and($cards[0]['score'])->toBe(0.1)                    // score carried for the pronounced badge
        ->and($cards[1]['id'])->toBe($reactive->id)
        ->and($cards[1]['source'])->toBe('Patch')
        ->and($cards[1]['date'])->toBe($reactive->created_at->toDateString());
});

it('candidate cards carry the timeliness pill and the article publish date from meta', function () {
    $site = opSite();
    $silo = opSilo($site);
    $candidate = Content::factory()->create([
        'site_id' => $site->id, 'kind' => ContentKind::Post, 'status' => ContentStatus::Candidate,
        'matched_silo_id' => $silo->id, 'relevance_score' => 0.9, 'source_name' => 'Patch',
        'meta' => ['classification' => 'time_sensitive', 'source_published_at' => '2026-08-01'],
    ]);

    $card = collect(app(BlogBoard::class)->candidates($site->id))->firstWhere('id', $candidate->id);

    expect($card['classification'])->toBe('Time-sensitive')
        ->and($card['classification_kind'])->toBe('time')
        ->and($card['date'])->toBe('2026-08-01');            // the article's real date, not ingest created_at
});

it('publishing cards carry silo, source, date + keyword for the top-row chips', function () {
    $site = opSite();
    $silo = opSilo($site);
    $kw = opKeyword($site, $silo, 'sump pump repair cost');
    $post = Content::factory()->create([
        'site_id' => $site->id, 'kind' => ContentKind::Post, 'status' => ContentStatus::Approved,
        'matched_silo_id' => $silo->id, 'target_keyword_id' => $kw->id, 'title' => 'Ready piece',
    ]);
    $post->releaseToPublish(); // released from Approved → on the Publish queue

    $card = collect(app(BlogBoard::class)->publishing($site->id))->firstWhere('id', $post->id);

    expect($card['silo'])->toBe('Sump Pumps')
        ->and($card['source'])->toBe('directed')
        ->and($card['keyword'])->toBe('sump pump repair cost')
        ->and($card['date'])->toBe($post->created_at->toDateString());
});

it('marks legacy-revival candidates with impressions + URL count and floats them above feed candidates', function () {
    $site = opSite();

    $feed = Content::factory()->create([
        'site_id' => $site->id, 'kind' => ContentKind::Post, 'status' => ContentStatus::Candidate,
        'relevance_score' => 0.95, 'source_name' => 'Patch',
    ]);
    $revived = Content::factory()->create([
        'site_id' => $site->id, 'kind' => ContentKind::Post, 'status' => ContentStatus::Candidate,
        'source_name' => 'Legacy revival (GSC)',
        'meta' => ['revived_from_urls' => ['/a', '/b', '/c'], 'revived_query' => 'sump pump renovation cost', 'revived_impressions' => 360109],
    ]);

    $cards = app(BlogBoard::class)->candidates($site->id);
    $card = collect($cards)->firstWhere('id', $revived->id);

    expect($card['revived'])->toBeTrue()
        ->and($card['revived_impressions'])->toBe(360109)
        ->and($card['revived_urls'])->toBe(3)
        ->and($cards[0]['id'])->toBe($revived->id)   // 360k reclaim floats above the higher-scored feed item
        ->and($feed->id)->not->toBe($revived->id);
});

it('promote queues the existing generate path; dismiss records a rejection', function () {
    Queue::fake();
    $site = opSite();
    $candidate = Content::factory()->create(['site_id' => $site->id, 'kind' => ContentKind::Post, 'status' => ContentStatus::Candidate, 'title' => 'Storm season prep']);
    $other = Content::factory()->create(['site_id' => $site->id, 'kind' => ContentKind::Post, 'status' => ContentStatus::Candidate]);

    Livewire::test(OperateBlog::class)
        ->call('promote', $candidate->id)
        ->call('dismissCandidate', $other->id);

    Queue::assertPushed(GeneratePost::class);
    expect($other->fresh()->status)->toBe(ContentStatus::Rejected)
        ->and($other->fresh()->reject_reason)->toBe('Dismissed at candidate triage');
});

it('one-click Approve runs the existing approve + publish path; Reject records the typed reason', function () {
    Queue::fake();
    $site = opSite();
    $draft = Content::factory()->create([
        'site_id' => $site->id, 'kind' => ContentKind::Post, 'status' => ContentStatus::NeedsReview,
        'title' => 'Why basements flood in spring', 'body' => 'A real drafted body.',
    ]);
    $bad = Content::factory()->create([
        'site_id' => $site->id, 'kind' => ContentKind::Post, 'status' => ContentStatus::NeedsReview, 'body' => 'Off-brand.',
    ]);

    $page = Livewire::test(OperateBlog::class, ['tab' => 'review'])
        ->call('approve', $draft->id);

    expect($draft->fresh()->status)->not->toBe(ContentStatus::NeedsReview);
    Queue::assertPushed(PublishContent::class);

    $page->call('startReject', $bad->id)
        ->set('rejectReason', 'Tone is off for this tenant')
        ->call('reject', $bad->id);
    expect($bad->fresh()->status)->toBe(ContentStatus::Rejected)
        ->and($bad->fresh()->reject_reason)->toBe('Tone is off for this tenant');
});

it('published groups by consumed keyword → pillar page, bare targets first, reactive under Freshness', function () {
    $site = opSite();
    $silo = opSilo($site);

    // A consumed target: published article linked via blog_targets.article_ref (the reactive-lane
    // linkage — the grouping must NOT rely on Content.target_keyword_id alone).
    $article = Content::factory()->create([
        'site_id' => $site->id, 'kind' => ContentKind::Post, 'status' => ContentStatus::Published,
        'matched_silo_id' => $silo->id, 'title' => 'Pedestal vs submersible, explained',
        'slug' => 'pedestal-vs-submersible', 'published_at' => now()->subDays(3), 'body' => 'x',
    ]);
    BlogTarget::withoutGlobalScopes()->create([
        'site_id' => $site->id, 'silo_id' => $silo->id,
        'keyword_id' => opKeyword($site, $silo, 'pedestal vs submersible sump pump')->id,
        'status' => BlogTargetStatus::Published, 'article_ref' => $article->id, 'queued_at' => now()->subDays(10),
    ]);
    // A bare queued target — the gap that must surface FIRST.
    BlogTarget::withoutGlobalScopes()->create([
        'site_id' => $site->id, 'silo_id' => $silo->id,
        'keyword_id' => opKeyword($site, $silo, 'sump pump battery lifespan')->id,
        'status' => BlogTargetStatus::Queued, 'queued_at' => now()->subDay(),
    ]);
    // A reactive article (no consumed keyword) → the silo Freshness bucket.
    Content::factory()->create([
        'site_id' => $site->id, 'kind' => ContentKind::Post, 'status' => ContentStatus::Published,
        'matched_silo_id' => $silo->id, 'title' => 'County storm alert: what to check',
        'slug' => 'county-storm-alert', 'published_at' => now()->subDay(), 'body' => 'x',
    ]);

    $groups = app(BlogBoard::class)->published($site->id);

    expect($groups)->toHaveCount(3)
        // Bare target (0 articles) sorts before both 1-article groups.
        ->and($groups[0]['keyword'])->toBe('sump pump battery lifespan')
        ->and($groups[0]['articles'])->toBe([]);

    $consumed = collect($groups)->first(fn ($g) => $g['keyword'] === 'pedestal vs submersible sump pump');
    expect($consumed['target_page'])->toBe('Sump Pumps Guide')
        ->and($consumed['target_url'])->toBe('https://spg.example/sump-pumps')
        ->and($consumed['articles'][0]['title'])->toBe('Pedestal vs submersible, explained')
        ->and($consumed['articles'][0]['url'])->toBe('https://spg.example/pedestal-vs-submersible');

    $freshness = collect($groups)->first(fn ($g) => $g['kind'] === 'freshness');
    expect($freshness['silo'])->toBe('Sump Pumps')
        ->and($freshness['articles'][0]['title'])->toBe('County storm alert: what to check');
});

it('scopes to the active tenant (no per-page switcher) and the silo filter persists across tabs + visits', function () {
    $site = opSite();
    opSite('OtherCo'); // a second tenant that must NOT be selectable from this page
    $silo = opSilo($site);
    session(['guided_site_id' => $site->id]); // the panel-wide active tenant

    $page = Livewire::test(OperateBlog::class)
        ->assertDontSee('All tenants') // the tenant switcher is gone
        ->set('siloFilter', $silo->id)
        ->call('setTab', 'review')
        ->call('setTab', 'published');

    // Scope is the active tenant; the silo filter rides along; the tab switch never clears it.
    expect($page->get('siteFilter'))->toBe($site->id)
        ->and($page->get('siloFilter'))->toBe($silo->id)
        ->and($page->get('tab'))->toBe('published');

    // A fresh visit re-derives the tenant from the panel and resumes the sticky silo filter.
    expect(session('operate_blog_silo'))->toBe($silo->id);
    Livewire::test(OperateBlog::class)->assertSet('siteFilter', $site->id);
});

it('the targets drawer lists the unconsumed queue and dismiss flips the status', function () {
    $site = opSite();
    $silo = opSilo($site);
    $target = BlogTarget::withoutGlobalScopes()->create([
        'site_id' => $site->id, 'silo_id' => $silo->id,
        'keyword_id' => opKeyword($site, $silo, 'french drain cost')->id,
        'status' => BlogTargetStatus::Queued, 'queued_at' => now(),
    ]);

    Livewire::test(OperateBlog::class)
        ->call('toggleTargets')
        ->assertSee('french drain cost')
        ->call('dismissTarget', $target->id);

    expect($target->fresh()->status)->toBe(BlogTargetStatus::Dismissed);
});

it('promote moves the candidate to Review as a writing card; undrafted review items can generate; thumbs render', function () {
    Queue::fake();
    Storage::fake(TenantStorage::DISK);
    $site = opSite();
    session(['guided_site_id' => $site->id]);
    $board = app(BlogBoard::class);

    // Promote: the candidate leaves the Candidates tab and shows in Review as "writing".
    $candidate = Content::factory()->create(['site_id' => $site->id, 'kind' => ContentKind::Post, 'status' => ContentStatus::Candidate, 'title' => 'Storm prep', 'body' => null]);
    Livewire::test(OperateBlog::class)->call('promote', $candidate->id);
    expect(collect($board->candidates($site->id))->pluck('id'))->not->toContain($candidate->id);
    $writing = collect($board->review($site->id))->firstWhere('id', $candidate->id);
    expect($writing['state'])->toBe('writing');

    // A borderline candidate routed to review WITHOUT a draft offers Generate (the same path).
    $undrafted = Content::factory()->create(['site_id' => $site->id, 'kind' => ContentKind::Post, 'status' => ContentStatus::InReview, 'title' => 'Borderline story', 'body' => null]);
    $card = collect($board->review($site->id))->firstWhere('id', $undrafted->id);
    expect($card['state'])->toBe('undrafted')->and($card['has_draft'])->toBeFalse();
    Livewire::test(OperateBlog::class)->call('promote', $undrafted->id);
    Queue::assertPushed(GeneratePost::class, 2);

    // A drafted card with a generate-time render shows its image thumbnail.
    $drafted = Content::factory()->create(['site_id' => $site->id, 'kind' => ContentKind::Post, 'status' => ContentStatus::NeedsReview, 'title' => 'Drafted piece', 'body' => 'Real copy.']);
    $drafted->renderJobs()->create(['site_id' => $site->id, 'status' => RenderStatus::Succeeded, 'r2_key' => 'tenants/spg/posts/drafted.jpg']);
    $withThumb = collect($board->review($site->id))->firstWhere('id', $drafted->id);
    expect($withThumb['image'])->not->toBeNull()
        ->and($withThumb['state'])->toBe('needs_review')
        // Review cards carry source + date so the card can show them alongside silo + photo.
        ->and($withThumb['source'])->toBe('feed')          // reactive, no source_name
        ->and($withThumb['date'])->toBe($drafted->created_at->toDateString());

    // A directed draft (has a target keyword) reads as source "directed".
    $kw = Keyword::factory()->create(['site_id' => $site->id, 'query' => 'sump pump repair']);
    $directed = Content::factory()->create(['site_id' => $site->id, 'kind' => ContentKind::Post, 'status' => ContentStatus::NeedsReview, 'title' => 'Directed piece', 'body' => 'Real copy.', 'target_keyword_id' => $kw->id]);
    $directedCard = collect($board->review($site->id))->firstWhere('id', $directed->id);
    expect($directedCard['source'])->toBe('directed');
});

it('regenerate re-drafts an already-drafted review item — it flips to writing and keeps its slug', function () {
    Queue::fake();
    $site = opSite();
    session(['guided_site_id' => $site->id]);
    $stale = Content::factory()->create([
        'site_id' => $site->id, 'kind' => ContentKind::Post, 'status' => ContentStatus::NeedsReview,
        'title' => 'Pre-pipeline draft', 'body' => 'Old copy from before the proper build.', 'slug' => 'pre-pipeline-draft',
    ]);

    Livewire::test(OperateBlog::class, ['tab' => 'review'])
        ->assertSee('Regenerate')
        ->call('regeneratePost', $stale->id);

    Queue::assertPushed(GeneratePost::class);
    $card = collect(app(BlogBoard::class)->review($site->id))->firstWhere('id', $stale->id);
    expect($card['state'])->toBe('writing')                     // in motion, polls itself
        ->and($stale->fresh()->slug)->toBe('pre-pipeline-draft'); // URL never churns on re-draft
});
