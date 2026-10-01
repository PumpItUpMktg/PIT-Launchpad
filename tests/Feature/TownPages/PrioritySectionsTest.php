<?php

use App\ContentEngine\Drafting\DraftCall;
use App\Enums\ContentKind;
use App\Enums\ContentStatus;
use App\Enums\MunicipalityType;
use App\Enums\PageType;
use App\Enums\UserRole;
use App\Filament\Pages\TownRankPage;
use App\Jobs\DraftPrioritySections;
use App\Jobs\PublishContent;
use App\Models\Content;
use App\Models\CoverageArea;
use App\Models\Keyword;
use App\Models\Location;
use App\Models\Scopes\SiteScope;
use App\Models\Service;
use App\Models\SiloBlueprint;
use App\Models\Site;
use App\Models\User;
use App\Models\WireframeKit;
use App\Publishing\Blocks\BlockContentAssembler;
use App\TownPages\PriorityKeywords;
use App\TownPages\PrioritySectionDrafter;
use App\TownPages\PrioritySectionPlan;
use App\TownPages\PrioritySections;
use App\TownPages\PrioritySectionWriter;
use App\TownPages\SectionUniqueness;
use Database\Seeders\WireframeKitSeeder;
use Illuminate\Support\Facades\Queue;
use Livewire\Livewire;
use Tests\Support\FakeClaudeClient;

/**
 * @return array{site: Site, parent: Location, service: Service, servicePage: Content, keyword: Keyword}
 */
function prioritySite(): array
{
    $site = Site::factory()->create(['domain_url' => 'https://sumppumpgurus.example', 'brand_name' => 'Sump Pump Gurus']);
    SiloBlueprint::create(['site_id' => $site->id, 'trade' => 'sump pump services']);
    $parent = Location::factory()->create([
        'site_id' => $site->id, 'name' => 'Hackettstown office', 'phone' => '(908) 555-0142', 'is_storefront' => false,
        'address_components' => [
            ['types' => ['locality'], 'long_name' => 'Hackettstown', 'short_name' => 'Hackettstown'],
            ['types' => ['administrative_area_level_1'], 'long_name' => 'New Jersey', 'short_name' => 'NJ'],
        ],
        'served_towns' => [],
    ]);
    $service = Service::factory()->create(['site_id' => $site->id, 'name' => 'Backup Sump Pumps', 'description' => 'Battery and water-powered backups that run when the power is out.']);
    (new WireframeKitSeeder)->run();
    $serviceKit = WireframeKit::query()->where('page_type', 'service')->orderByDesc('version')->firstOrFail();
    $servicePage = Content::factory()->create([
        'site_id' => $site->id, 'kind' => ContentKind::Page, 'page_type' => PageType::Service, 'status' => ContentStatus::Published,
        'title' => 'Backup Sump Pumps', 'slug' => 'backup-sump-pumps', 'wp_post_id' => 77, 'primary_service_id' => $service->id, 'wireframe_kit_id' => $serviceKit->id,
    ]);
    $keyword = Keyword::factory()->create([
        'site_id' => $site->id, 'query' => 'backup sump pump installation', 'track_town_rank' => true, 'target_content_id' => $servicePage->id,
    ]);

    return ['site' => $site, 'parent' => $parent, 'service' => $service, 'servicePage' => $servicePage, 'keyword' => $keyword];
}

/** A published town page under the parent office, with its Census row (population decides the tier). */
function priorityTown(array $f, string $name, string $geoId, int $population, array $overrides = []): Content
{
    CoverageArea::factory()->create([
        'site_id' => $f['site']->id, 'name' => $name, 'state' => 'NJ', 'type' => MunicipalityType::CountySubdivision,
        'geo_id' => $geoId, 'lat' => 40.85, 'lng' => -74.83, 'population' => $population, 'source_location_ids' => [$f['parent']->id],
    ]);
    $kit = WireframeKit::query()->where('page_type', 'location')->orderByDesc('version')->firstOrFail();

    return Content::factory()->create(array_merge([
        'site_id' => $f['site']->id, 'kind' => ContentKind::Page, 'page_type' => PageType::Location, 'status' => ContentStatus::Published,
        'location_id' => null, 'parent_location_id' => $f['parent']->id, 'geo_id' => $geoId,
        'title' => "{$name}, NJ", 'slug' => strtolower($name).'-nj', 'wp_post_id' => 100 + $population % 97, 'wireframe_kit_id' => $kit->id,
        'slot_payload' => [
            'loc_intro' => "We have kept basements dry around {$name} for years.",
            'faq' => [['question' => 'Do you serve the whole township?', 'answer' => 'Yes, every street.']],
        ],
    ], $overrides));
}

function priorityDraft(Keyword $keyword, string $town, string $body): string
{
    $id = $keyword->id;

    return "<<<SLOT:section.{$id}.heading>>>\nBackup sump pump installation in {$town}\n<<<END>>>\n"
        ."<<<SLOT:section.{$id}.body>>>\n{$body}\n<<<END>>>\n"
        ."<<<SLOT:faq.{$id}>>>\nDo you install backup sump pumps in {$town}? || Yes — battery and water-powered systems, sized to the pit you have.\n<<<END>>>\n"
        ."<<<SLOT:faq.{$id}>>>\nWhen does a {$town} home need a backup pump || When the primary pump runs through every storm and the power goes with it.\n<<<END>>>";
}

it('keeps at most three priority keywords, ranks them in the order picked, and closes the gap when one comes off', function () {
    $f = prioritySite();
    $site = $f['site'];
    $kw = [$f['keyword']];
    foreach (['sewage ejector pump', 'french drain installation', 'crawl space encapsulation'] as $q) {
        $kw[] = Keyword::factory()->create(['site_id' => $site->id, 'query' => $q, 'track_town_rank' => true]);
    }
    $svc = app(PriorityKeywords::class);
    $svc->set($site, $kw[0], true);
    $svc->set($site, $kw[1], true);
    $svc->set($site, $kw[2], true);

    expect(fn () => $svc->set($site, $kw[3], true))->toThrow(InvalidArgumentException::class, 'Up to 3')
        ->and(array_map(fn (Keyword $k) => [$k->query, $k->town_priority_rank], $svc->for($site)))
        ->toBe([['backup sump pump installation', 1], ['sewage ejector pump', 2], ['french drain installation', 3]]);

    $svc->set($site, $kw[1], false);
    expect(array_map(fn (Keyword $k) => [$k->query, $k->town_priority_rank], $svc->for($site)))
        ->toBe([['backup sump pump installation', 1], ['french drain installation', 2]]);

    // Tiers: a big town carries all, a mid-size one the first, a hamlet none.
    config()->set('launchpad.town_pages.priority', ['max_keywords' => 3, 'full_population' => 10000, 'partial_population' => 3000, 'max_similarity' => 0.5]);
    expect(array_map(fn (Keyword $k) => $k->query, $svc->forPopulation($site, 25000)))->toBe(['backup sump pump installation', 'french drain installation'])
        ->and(array_map(fn (Keyword $k) => $k->query, $svc->forPopulation($site, 4000)))->toBe(['backup sump pump installation'])
        ->and($svc->forPopulation($site, 900))->toBe([])
        ->and(PriorityKeywords::tier(10000))->toBe('full')
        ->and(PriorityKeywords::tier(2999))->toBe('none');

    // The keyword's service: its target page's primary service.
    expect($svc->serviceFor($kw[0])?->id)->toBe($f['service']->id)
        ->and($svc->serviceFor($kw[2]))->toBeNull();
});

it('drafts one section and two FAQs per keyword in one call, grounded on the town alone', function () {
    $f = prioritySite();
    $town = priorityTown($f, 'Hackettstown', '3404128590', 12000);
    app(PriorityKeywords::class)->set($f['site'], $f['keyword'], true);

    $fake = new FakeClaudeClient(priorityDraft($f['keyword'], 'Hackettstown', 'Hackettstown homes on the river flats see the water table rise every spring, so a primary pump alone leaves the basement one outage from a flood. A battery backup keeps pumping through the storm that takes the power out.'));
    app()->instance(DraftCall::class, new DraftCall($fake));

    $drafter = app(PrioritySectionDrafter::class);
    $attempt = $drafter->attempt($town, [$f['keyword']]);
    $sections = $drafter->parse($attempt, [$f['keyword']]);

    expect($fake->prompts)->toHaveCount(1)
        ->and($fake->prompts[0])->toContain('TOWN (this page\'s ONE subject — name it as written): Hackettstown, NJ')
        ->toContain('KEYWORD '.$f['keyword']->id.': "backup sump pump installation"')
        ->toContain('"name": "Backup Sump Pumps"')
        ->toContain('<<<SLOT:section.'.$f['keyword']->id.'.heading>>>')
        ->and($sections)->toHaveCount(1)
        ->and($sections[0]['heading'])->toBe('Backup sump pump installation in Hackettstown')
        ->and($sections[0]['service_id'])->toBe($f['service']->id)
        ->and($sections[0]['faqs'])->toHaveCount(2)
        ->and($sections[0]['faqs'][1]['question'])->toBe('When does a Hackettstown home need a backup pump?');
});

it('refuses a section that differs from another town\'s only by the town name', function () {
    $base = 'The older housing stock here means stone foundations that weep every spring, so a battery backup keeps the pit dry when the storm takes the power out.';
    $same = SectionUniqueness::check(
        str_replace('here', 'in Warren', $base), 'Warren',
        [['body' => str_replace('here', 'in Hackettstown', $base), 'town' => 'Hackettstown']],
    );
    $different = SectionUniqueness::check(
        'Warren sits on a ridge with sandy soil, so water moves fast and the primary pump cycles hard in a storm; a backup takes over when it fails or the power drops.', 'Warren',
        [['body' => str_replace('here', 'in Hackettstown', $base), 'town' => 'Hackettstown']],
    );

    expect($same['ok'])->toBeFalse()->and($same['similarity'])->toBeGreaterThan(0.5)
        ->and($different['ok'])->toBeTrue()->and($different['similarity'])->toBeLessThan(0.2);
});

it('stores the sections on meta, refuses a templated one, and drops a keyword no longer expected', function () {
    $f = prioritySite();
    $svc = app(PriorityKeywords::class);
    $svc->set($f['site'], $f['keyword'], true);
    $hack = priorityTown($f, 'Hackettstown', '3404128590', 12000);
    $warren = priorityTown($f, 'Washington', '3404177000', 11000);
    $writer = app(PrioritySectionWriter::class);
    $section = fn (string $town, string $body): array => [
        'keyword_id' => $f['keyword']->id, 'keyword' => $f['keyword']->query, 'service_id' => $f['service']->id,
        'heading' => "Backup sump pump installation in {$town}", 'body' => $body,
        'faqs' => [['question' => "Backups in {$town}?", 'answer' => 'Yes.']],
    ];
    $body = 'The older housing stock in {town} means stone foundations that weep every spring, so a battery backup keeps the pit dry when the storm takes the power out.';

    $first = $writer->write($hack, [$section('Hackettstown', str_replace('{town}', 'Hackettstown', $body))], [$f['keyword']]);
    $second = $writer->write($warren, [$section('Washington', str_replace('{town}', 'Washington', $body))], [$f['keyword']]);

    expect($first['stored'])->toBe([$f['keyword']->id])
        ->and(app(PrioritySections::class)->live($hack->fresh()))->toHaveCount(1)
        ->and($second['stored'])->toBe([])
        ->and($second['refused'][0]['keyword_id'])->toBe($f['keyword']->id)
        ->and(app(PrioritySections::class)->stored($warren->fresh()))->toBe([]);

    // The keyword comes off the list: it no longer renders, and the next write drops it from storage.
    $svc->set($f['site'], $f['keyword'], false);
    expect(app(PrioritySections::class)->live($hack->fresh()))->toBe([]);
    $gone = $writer->write($hack->fresh(), [], []);
    expect($gone['dropped'])->toBe([$f['keyword']->id])
        ->and(app(PrioritySections::class)->stored($hack->fresh()))->toBe([]);
});

it('renders the section as an H2 with the drafted paragraph and a link to the live service page, replacing that service\'s card', function () {
    $f = prioritySite();
    app(PriorityKeywords::class)->set($f['site'], $f['keyword'], true);
    Service::factory()->create(['site_id' => $f['site']->id, 'name' => 'French Drains', 'description' => 'Interior drainage done right.']);
    $town = priorityTown($f, 'Hackettstown', '3404128590', 12000);
    app(PrioritySectionWriter::class)->write($town, [[
        'keyword_id' => $f['keyword']->id, 'keyword' => $f['keyword']->query, 'service_id' => $f['service']->id,
        'heading' => 'Backup sump pump installation in Hackettstown',
        'body' => 'Hackettstown homes on the river flats see the water table rise every spring. A battery backup keeps pumping through the storm that takes the power out.',
        'faqs' => [['question' => 'Do you install backup sump pumps in Hackettstown?', 'answer' => 'Yes — sized to the pit you have.']],
    ]], [$f['keyword']]);

    $markup = app(BlockContentAssembler::class)->compose($town->fresh(), $town->slot_payload, []);

    expect($markup)->toContain('Backup sump pump installation in Hackettstown')
        ->toContain('lp-keyword-section')
        ->toContain('river flats see the water table rise')
        ->toContain('href="https://sumppumpgurus.example/backup-sump-pumps/"')
        ->toContain('More about Backup Sump Pumps')
        ->toContain('French Drains');
    // The service with its own section is not ALSO a card: its name appears once, in the section's link.
    expect(substr_count($markup, 'Backup Sump Pumps'))->toBe(1);

    // Off the list → the section is gone on the next compose, the card is back, nothing redrafted.
    app(PriorityKeywords::class)->set($f['site'], $f['keyword'], false);
    $again = app(BlockContentAssembler::class)->compose($town->fresh(), $town->slot_payload, []);
    expect($again)->not->toContain('lp-keyword-section')
        ->and(substr_count($again, 'Backup Sump Pumps'))->toBeGreaterThanOrEqual(1);
});

it('appends the priority Q&As to the page FAQ list within the kit\'s cap of eight', function () {
    $f = prioritySite();
    app(PriorityKeywords::class)->set($f['site'], $f['keyword'], true);
    $town = priorityTown($f, 'Hackettstown', '3404128590', 12000);
    app(PrioritySectionWriter::class)->write($town, [[
        'keyword_id' => $f['keyword']->id, 'keyword' => $f['keyword']->query, 'service_id' => null,
        'heading' => 'Backup sump pump installation in Hackettstown', 'body' => 'Hackettstown homes on the river flats need a backup.',
        'faqs' => [['question' => 'Q-A?', 'answer' => 'A.'], ['question' => 'Q-B?', 'answer' => 'B.']],
    ]], [$f['keyword']]);
    $own = array_map(fn (int $i) => ['question' => "Own {$i}?", 'answer' => "Own answer {$i}."], range(1, 8));

    $merged = app(PrioritySections::class)->withFaqs($town->fresh(), $own);

    expect($merged)->toHaveCount(8)
        ->and(array_column($merged, 'question'))->toBe(['Own 1?', 'Own 2?', 'Own 3?', 'Own 4?', 'Own 5?', 'Own 6?', 'Q-A?', 'Q-B?']);
});

it('reports every town by tier and state, and --execute queues one draft job per page that needs work', function () {
    Queue::fake();
    $f = prioritySite();
    app(PriorityKeywords::class)->set($f['site'], $f['keyword'], true);
    priorityTown($f, 'Hackettstown', '3404128590', 12000);
    priorityTown($f, 'Independence', '3404133000', 5000);
    priorityTown($f, 'Allamuchy', '3404100700', 900);

    $plan = app(PrioritySectionPlan::class)->for($f['site']);
    expect($plan['tiers'])->toBe(['full' => 1, 'partial' => 1, 'none' => 1])
        ->and($plan['counts'])->toBe(['current' => 0, 'missing' => 2, 'stale' => 0, 'none' => 1, 'queued' => 0])
        ->and($plan['keywords'][0]['service'])->toBe('Backup Sump Pumps');

    $this->artisan('launchpad:priority-sections', ['--site' => 'Sump Pump Gurus'])
        ->expectsOutputToContain('1. backup sump pump installation → Backup Sump Pumps')
        ->expectsOutputToContain('0 current · 2 missing · 0 stale · 0 queued · 1 none by tier')
        ->assertSuccessful();
    Queue::assertNothingPushed();

    // Largest town first, so a --limit wave is the biggest towns: Hackettstown (12,000) before Independence (5,000).
    expect(array_column($plan['pages'], 'title'))->toBe(['Hackettstown, NJ', 'Independence, NJ', 'Allamuchy, NJ']);
    $hack = Content::withoutGlobalScope(SiteScope::class)->where('title', 'Hackettstown, NJ')->firstOrFail();

    $this->artisan('launchpad:priority-sections', ['--site' => 'Sump Pump Gurus', '--execute' => true, '--repush' => true, '--limit' => 1])
        ->expectsOutputToContain('Queued 1 draft job(s) with re-push')
        ->expectsOutputToContain('This wave: Hackettstown, NJ (12,000)')
        ->assertSuccessful();
    Queue::assertPushed(DraftPrioritySections::class, fn (DraftPrioritySections $job) => $job->repush === true && $job->contentId === $hack->id);
});

it('the job drafts, stores, and re-pushes a town page; a town below the tier drafts nothing', function () {
    Queue::fake();
    $f = prioritySite();
    app(PriorityKeywords::class)->set($f['site'], $f['keyword'], true);
    $hack = priorityTown($f, 'Hackettstown', '3404128590', 12000);
    $hamlet = priorityTown($f, 'Allamuchy', '3404100700', 900);

    $fake = new FakeClaudeClient(priorityDraft($f['keyword'], 'Hackettstown', 'Hackettstown homes on the river flats see the water table rise every spring, so a backup pump earns its keep the first time the power drops mid-storm.'));
    app()->instance(DraftCall::class, new DraftCall($fake));

    app()->call([new DraftPrioritySections($hack->id, true), 'handle']);
    app()->call([new DraftPrioritySections($hamlet->id, true), 'handle']);

    expect(app(PrioritySections::class)->live($hack->fresh()))->toHaveCount(1)
        ->and($fake->prompts)->toHaveCount(1)
        ->and(app(PrioritySections::class)->stored($hamlet->fresh()))->toBe([])
        ->and(app(PrioritySectionPlan::class)->for($f['site'])['counts'])->toBe(['current' => 1, 'missing' => 0, 'stale' => 0, 'none' => 1, 'queued' => 0]);
    Queue::assertPushed(PublishContent::class, 2);
    expect(Content::withoutGlobalScope(SiteScope::class)->find($hack->id)->meta['priority_sections_error'] ?? null)->toBeNull();
});

it('the Town Rank wall stars a keyword as priority and un-stars it, with the rank on the card', function () {
    $this->actingAs(User::factory()->create(['role' => UserRole::Operator]));
    $f = prioritySite();

    Livewire::test(TownRankPage::class)
        ->set('siteId', $f['site']->id)
        ->assertOk()
        ->assertSee('☆ Priority')
        ->call('togglePriority', $f['keyword']->id)
        ->assertSee('★ Priority #1')
        ->call('togglePriority', $f['keyword']->id)
        ->assertSee('☆ Priority')
        ->assertDontSee('★ Priority #1');

    expect($f['keyword']->fresh()->town_priority_rank)->toBeNull();
});
