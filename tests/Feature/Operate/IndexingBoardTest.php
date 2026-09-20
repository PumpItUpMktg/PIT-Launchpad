<?php

use App\Enums\ContentStatus;
use App\Enums\IndexCoverageState;
use App\Enums\UserRole;
use App\Filament\Pages\IndexingBoard;
use App\Jobs\SyncSiteMetrics;
use App\Metrics\Providers\IndexMetricProvider;
use App\Models\Content;
use App\Models\PageIndexState;
use App\Models\Scopes\SiteScope;
use App\Models\Site;
use App\Models\User;
use App\Operator\ActiveTenant;
use App\Operator\Coverage\IndexStandings;
use Filament\Facades\Filament;
use Illuminate\Support\Facades\Queue;
use Livewire\Livewire;

beforeEach(function () {
    Filament::setCurrentPanel('admin');
    $this->actingAs(User::factory()->create(['role' => UserRole::Operator]));
});

function indexRow(Site $s, ?Content $content, string $verdict, string $url): void
{
    PageIndexState::create([
        'site_id' => $s->id,
        'content_id' => $content?->id,
        'url' => $url,
        'url_normalized' => rtrim($url, '/'),
        'coverage_state' => $verdict === 'PASS' ? 'indexed' : $verdict,
        'index_verdict' => $verdict,
    ]);
}

it('is operator-only', function () {
    expect(IndexingBoard::canAccess())->toBeTrue();
    $this->actingAs(User::factory()->create(['role' => UserRole::Client]));
    expect(IndexingBoard::canAccess())->toBeFalse();
});

it('splits published (in-sitemap) from all-known', function () {
    $site = Site::factory()->create();
    // Actually published — the panel counts the pages a visitor can reach, so a draft carrying a leftover
    // verdict row does not belong in it. The fixture said "published" in a comment and never set it.
    $p1 = Content::factory()->create(['site_id' => $site->id, 'status' => ContentStatus::Published]);
    $p2 = Content::factory()->create(['site_id' => $site->id, 'status' => ContentStatus::Published]);

    // Two published pages: one indexed, one not.
    indexRow($site, $p1, 'PASS', 'https://x/a');
    indexRow($site, $p2, IndexCoverageState::CrawledNotIndexed->value, 'https://x/b');
    // Three discovered-only archive URLs (no content_id).
    indexRow($site, null, IndexCoverageState::DiscoveredNotIndexed->value, 'https://x/category/1');
    indexRow($site, null, IndexCoverageState::DiscoveredNotIndexed->value, 'https://x/category/2');
    indexRow($site, null, IndexCoverageState::ExcludedCanonical->value, 'https://x/tag/1');

    $board = app(IndexStandings::class)->for($site->id);

    expect($board['published']['total'])->toBe(2)
        ->and($board['published']['indexed'])->toBe(1)
        ->and($board['published']['not_indexed'])->toBe(1)
        ->and($board['all_known']['total'])->toBe(5)
        ->and($board['discovered_only'])->toBe(3);
});

it('breaks down non-indexed URLs by reason (grouped by index_verdict), biggest first', function () {
    $site = Site::factory()->create();
    indexRow($site, null, IndexCoverageState::DiscoveredNotIndexed->value, 'https://x/1');
    indexRow($site, null, IndexCoverageState::DiscoveredNotIndexed->value, 'https://x/2');
    indexRow($site, null, IndexCoverageState::DiscoveredNotIndexed->value, 'https://x/3');
    indexRow($site, null, IndexCoverageState::CrawledNotIndexed->value, 'https://x/4');
    indexRow($site, null, IndexCoverageState::ExcludedRedirect->value, 'https://x/5');

    $reasons = app(IndexStandings::class)->for($site->id)['all_known']['reasons'];

    // Biggest reason first, resolved to its human label; excluded-redirect is present as a reason row too.
    expect($reasons[0]['state'])->toBe(IndexCoverageState::DiscoveredNotIndexed->value)
        ->and($reasons[0]['count'])->toBe(3)
        ->and($reasons[0]['label'])->toBe('Discovered — not indexed')
        ->and(collect($reasons)->pluck('state'))->toContain(IndexCoverageState::CrawledNotIndexed->value);
});

it('counts a redirect/canonical as a correct exclusion, not pending', function () {
    $site = Site::factory()->create();
    indexRow($site, null, IndexCoverageState::ExcludedRedirect->value, 'https://x/r');
    indexRow($site, null, IndexCoverageState::ExcludedCanonical->value, 'https://x/c');
    indexRow($site, null, IndexCoverageState::CrawledNotIndexed->value, 'https://x/n');

    $all = app(IndexStandings::class)->for($site->id)['all_known'];

    expect($all['excluded'])->toBe(2)   // redirect + canonical
        ->and($all['not_indexed'])->toBe(1); // only the crawled-not-indexed one
});

it('scopes index coverage to the locked tenant', function () {
    $a = Site::factory()->create();
    $b = Site::factory()->create();
    indexRow($a, null, 'PASS', 'https://a/1');
    indexRow($b, null, 'PASS', 'https://b/1');

    expect(app(IndexStandings::class)->for($a->id)['all_known']['total'])->toBe(1);
});

it('reports coverage lag (inspected of published) and the data-through date', function () {
    $site = Site::factory()->create();
    // 3 published pages, only 2 inspected → the panel must show the gap, not read 2 as the whole site.
    $p1 = Content::factory()->create(['site_id' => $site->id, 'status' => ContentStatus::Published]);
    $p2 = Content::factory()->create(['site_id' => $site->id, 'status' => ContentStatus::Published]);
    Content::factory()->create(['site_id' => $site->id, 'status' => ContentStatus::Published]); // published, never inspected
    indexRow($site, $p1, 'PASS', 'https://x/a');
    indexRow($site, $p2, IndexCoverageState::CrawledNotIndexed->value, 'https://x/b');
    PageIndexState::withoutGlobalScope(SiteScope::class)->update(['last_inspected_at' => '2026-08-20 10:00:00']);

    $board = app(IndexStandings::class)->for($site->id);

    expect($board['inspected_count'])->toBe(2)
        ->and($board['published_content_count'])->toBe(3)
        ->and($board['coverage_gap'])->toBe(1)          // 112 not-yet-inspected, in miniature
        ->and($board['data_through'])->toBe('2026-08-20')
        ->and($board['last_inspected_at'])->toBe('2026-08-20 10:00:00'); // raw anchor for the stamp
});

it('renders "inspected of published" and the shared freshness stamp on the board', function () {
    $site = Site::factory()->create();
    $p = Content::factory()->create(['site_id' => $site->id, 'status' => ContentStatus::Published]);
    Content::factory()->create(['site_id' => $site->id, 'status' => ContentStatus::Published]); // uninspected
    indexRow($site, $p, 'PASS', 'https://x/p');
    PageIndexState::withoutGlobalScope(SiteScope::class)->update(['last_inspected_at' => '2026-08-20 10:00:00']);
    app(ActiveTenant::class)->set($site->id);

    $html = Livewire::test(IndexingBoard::class)->assertOk()->html();

    expect($html)->toContain('inspected of')
        ->toContain('Index data as of 20 Aug')   // the shared stamp, not a bespoke "data through" line
        ->toContain('data-fresh-state=')          // semantic freshness state in the markup
        ->toContain('not yet inspected');         // the 1-page gap surfaced
});

it('renders an honest never-checked freshness stamp when no verdicts are synced', function () {
    $site = Site::factory()->create();
    Content::factory()->create(['site_id' => $site->id, 'status' => ContentStatus::Published]); // published, never inspected
    app(ActiveTenant::class)->set($site->id);

    $html = Livewire::test(IndexingBoard::class)->assertOk()->html();

    expect($html)->toContain('Index data — never checked')
        ->toContain('data-fresh-state="never_checked"');
});

it('reports whether the all-known capture path is enabled (config-driven)', function () {
    $site = Site::factory()->create();
    indexRow($site, null, 'PASS', 'https://x/1');

    config()->set('launchpad.indexing.all_known_capture', false);
    expect(app(IndexStandings::class)->for($site->id)['all_known_available'])->toBeFalse();

    config()->set('launchpad.indexing.all_known_capture', true);
    expect(app(IndexStandings::class)->for($site->id)['all_known_available'])->toBeTrue();
});

it('shows an honest "not yet enabled" state for all-known by default — never a silent empty panel', function () {
    config()->set('launchpad.indexing.all_known_capture', false);
    $site = Site::factory()->create();
    $p = Content::factory()->create(['site_id' => $site->id]);
    indexRow($site, $p, 'PASS', 'https://x/p');
    indexRow($site, null, IndexCoverageState::DiscoveredNotIndexed->value, 'https://x/category/1');
    app(ActiveTenant::class)->set($site->id);

    $html = Livewire::test(IndexingBoard::class)->assertOk()->html();

    // Published side renders for real; the all-known side declares it's off rather than showing archives.
    expect($html)->toContain('Pages you published')
        ->and($html)->toContain('All-known capture not yet enabled')
        ->and($html)->not->toContain('Discovered — not indexed') // gated off
        ->and($html)->not->toContain('<select');
});

it('renders the all-known reason breakdown once the capture is enabled', function () {
    config()->set('launchpad.indexing.all_known_capture', true);
    $site = Site::factory()->create();
    $p = Content::factory()->create(['site_id' => $site->id]);
    indexRow($site, $p, 'PASS', 'https://x/p');
    indexRow($site, null, IndexCoverageState::DiscoveredNotIndexed->value, 'https://x/category/1');
    app(ActiveTenant::class)->set($site->id);

    $html = Livewire::test(IndexingBoard::class)->assertOk()->html();

    expect($html)->toContain('Discovered — not indexed')
        ->and($html)->not->toContain('All-known capture not yet enabled')
        ->and($html)->not->toContain('<select');
});

/**
 * The re-check button. Indexing verdicts came only from the daily sync, so an operator who had just
 * fixed the thing that was blocking indexing — a robots.txt Disallow, a stray noindex — had no way to
 * ask Google again except to wait a day and hope.
 *
 * It queues rather than inspecting inline: the run is minutes of HTTP against Search Console and has no
 * business holding a web request open.
 */
it('queues a Search Console re-inspection from the indexing board', function () {
    Queue::fake();
    $this->actingAs(User::factory()->create(['role' => UserRole::Operator]));
    $site = Site::factory()->create(['domain_url' => 'https://spg.example']);

    Livewire::test(IndexingBoard::class)
        ->set('siteId', $site->id)
        ->callAction('recheckIndexing')
        ->assertOk();

    Queue::assertPushed(SyncSiteMetrics::class, fn (SyncSiteMetrics $j): bool => $j->siteId === (string) $site->id
        && $j->provider === IndexMetricProvider::PROVIDER);
});

/** No site selected posts nothing — a re-check with no tenant would inspect someone else's URLs or none. */
it('queues nothing when no site is selected', function () {
    Queue::fake();
    $this->actingAs(User::factory()->create(['role' => UserRole::Operator]));

    Livewire::test(IndexingBoard::class)
        ->set('siteId', null)
        ->callAction('recheckIndexing')
        ->assertOk();

    Queue::assertNothingPushed();
});

it('reports the vintage RANGE, not just the newest verdict', function () {
    $site = Site::factory()->create();
    $recent = Content::factory()->create(['site_id' => $site->id, 'status' => ContentStatus::Published]);
    $old = Content::factory()->create(['site_id' => $site->id, 'status' => ContentStatus::Published]);
    indexRow($site, $recent, 'PASS', 'https://x/fresh');
    indexRow($site, $old, 'PASS', 'https://x/old');

    // The shape a budget-capped inspector actually leaves behind: one URL re-checked this morning, one
    // last touched three weeks ago.
    PageIndexState::withoutGlobalScope(SiteScope::class)->where('url', 'https://x/fresh')
        ->update(['last_inspected_at' => now()->subHour()]);
    PageIndexState::withoutGlobalScope(SiteScope::class)->where('url', 'https://x/old')
        ->update(['last_inspected_at' => now()->subWeeks(3)]);

    $fresh = app(IndexStandings::class)->for($site->id)['freshness'];

    expect($fresh['newest'])->toBe(now()->toDateString())
        ->and($fresh['oldest'])->toBe(now()->subWeeks(3)->toDateString())
        ->and($fresh['total'])->toBe(2)
        // Index cadence is daily, so the three-week-old verdict is overdue and the hour-old one is not.
        ->and($fresh['stale'])->toBe(1)
        ->and($fresh['interval_days'])->toBe(1.0);
});

it('does not count a never-inspected row as stale — that is the coverage gap, already reported', function () {
    $site = Site::factory()->create();
    $p = Content::factory()->create(['site_id' => $site->id, 'status' => ContentStatus::Published]);
    indexRow($site, $p, 'PASS', 'https://x/unstamped');   // no last_inspected_at at all

    $board = app(IndexStandings::class)->for($site->id);

    expect($board['freshness']['total'])->toBe(0)
        ->and($board['freshness']['stale'])->toBe(0)
        ->and($board['freshness']['newest'])->toBeNull();
});

it('collapses the range to one date when every verdict was checked together', function () {
    $site = Site::factory()->create();
    foreach (['a', 'b'] as $slug) {
        $c = Content::factory()->create(['site_id' => $site->id, 'status' => ContentStatus::Published]);
        indexRow($site, $c, 'PASS', 'https://x/'.$slug);
    }
    PageIndexState::withoutGlobalScope(SiteScope::class)->update(['last_inspected_at' => now()->subHours(2)]);
    app(ActiveTenant::class)->set($site->id);

    $board = app(IndexStandings::class)->for($site->id);
    expect($board['freshness']['oldest'])->toBe($board['freshness']['newest']);

    // A site small enough to inspect in one pass says so plainly rather than drawing a range of one day.
    expect(Livewire::test(IndexingBoard::class)->assertOk()->html())
        ->toContain('All 2 verdicts checked today');
});

it('shows the spread and the overdue count on the board', function () {
    $site = Site::factory()->create();
    $a = Content::factory()->create(['site_id' => $site->id, 'status' => ContentStatus::Published]);
    $b = Content::factory()->create(['site_id' => $site->id, 'status' => ContentStatus::Published]);
    indexRow($site, $a, 'PASS', 'https://x/a');
    indexRow($site, $b, 'PASS', 'https://x/b');
    PageIndexState::withoutGlobalScope(SiteScope::class)->where('url', 'https://x/a')
        ->update(['last_inspected_at' => now()->subHour()]);
    PageIndexState::withoutGlobalScope(SiteScope::class)->where('url', 'https://x/b')
        ->update(['last_inspected_at' => now()->subDays(10)]);
    app(ActiveTenant::class)->set($site->id);

    $html = Livewire::test(IndexingBoard::class)->assertOk()->html();

    expect($html)->toContain('Verdicts checked between')
        ->toContain(now()->subDays(10)->format('j M'))
        ->toContain('older than a day');
});

it('stays quiet about vintage with no tenant selected', function () {
    expect(app(IndexStandings::class)->for(null)['freshness'])
        ->toBe(['oldest' => null, 'newest' => null, 'stale' => 0, 'total' => 0, 'interval_days' => null]);
});
