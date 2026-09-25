<?php

use App\Enums\ContentKind;
use App\Enums\ContentStatus;
use App\Enums\IndexCoverageState;
use App\Enums\PageType;
use App\Enums\UserRole;
use App\Filament\Pages\IndexingBoard;
use App\Integrations\UrlInspection\IndexInspector;
use App\Integrations\UrlInspection\IndexStatus;
use App\Models\Content;
use App\Models\GscUrlDaily;
use App\Models\PageIndexState;
use App\Models\Site;
use App\Models\User;
use App\Operator\ActiveTenant;
use App\Operator\Coverage\IndexWatchlist;
use App\Support\PublicUrl;
use Filament\Facades\Filament;
use Livewire\Livewire;

function watchSite(): Site
{
    return Site::factory()->create(['domain_url' => 'https://watch.example']);
}

function watchPage(Site $site, string $title, string $publishedAt, string $slug): Content
{
    return Content::factory()->create([
        'site_id' => $site->id, 'kind' => ContentKind::Page, 'page_type' => PageType::Location,
        'status' => ContentStatus::Published, 'title' => $title, 'slug' => $slug, 'published_at' => $publishedAt, 'wp_post_id' => 1,
    ]);
}

function watchVerdict(Site $site, Content $page, string $verdict, string $inspectedAt, ?string $indexedAt = null): void
{
    $url = PublicUrl::forContent($site->domain_url, $page);
    PageIndexState::create([
        'site_id' => $site->id, 'content_id' => $page->id, 'url' => $url, 'url_normalized' => rtrim((string) $url, '/'),
        'coverage_state' => $verdict, 'index_verdict' => $verdict, 'last_inspected_at' => $inspectedAt, 'indexed_at' => $indexedAt,
    ]);
}

beforeEach(fn () => $this->travelTo('2026-09-24 12:00:00'));

it('lists published pages by state — plain until inspected, amber when inspected and not indexed, green when it lands', function () {
    $site = watchSite();
    $plain = watchPage($site, 'Plain Town', '2026-09-20 09:00:00', 'plain-town');
    $amber = watchPage($site, 'Amber Town', '2026-09-10 09:00:00', 'amber-town');
    $green = watchPage($site, 'Green Town', '2026-09-15 09:00:00', 'green-town');
    watchVerdict($site, $amber, IndexCoverageState::CrawledNotIndexed->value, '2026-09-22 03:00:00');
    watchVerdict($site, $green, 'PASS', '2026-09-23 03:00:00', indexedAt: '2026-09-22 03:00:00');

    $list = app(IndexWatchlist::class)->for($site);

    expect($list['waiting'])->toBe(1)->and($list['inspected'])->toBe(1)->and($list['landed'])->toBe(1)
        ->and(collect($list['rows'])->pluck('state', 'title')->all())->toBe(['Plain Town' => 'published', 'Amber Town' => 'inspected', 'Green Town' => 'indexed']); // default: by status, waiting → inspected → landed

    $rows = collect($list['rows'])->keyBy('title');
    expect($rows['Amber Town']['reason'])->toBe('Crawled — not indexed')
        ->and($rows['Amber Town']['inspected_at'])->toBe('2026-09-22')
        ->and($rows['Amber Town']['days_waiting'])->toBe(14)
        ->and($rows['Plain Town']['inspected_at'])->toBeNull()
        ->and($rows['Green Town']['indexed_at'])->toBe('2026-09-22')
        ->and($rows['Green Town']['days_waiting'])->toBe(7)   // published → indexed
        ->and($rows['Green Town']['url'])->toBe('https://watch.example/green-town/');
});

it('drops an indexed page off the list once it has been indexed for longer than the watch window', function () {
    config(['launchpad.indexing.watch_days' => 5]);
    $site = watchSite();
    $fresh = watchPage($site, 'Fresh', '2026-09-01 09:00:00', 'fresh');
    $old = watchPage($site, 'Old', '2026-09-01 09:00:00', 'old');
    $unstamped = watchPage($site, 'Unstamped', '2026-09-01 09:00:00', 'unstamped');
    watchVerdict($site, $fresh, 'PASS', '2026-09-24 03:00:00', indexedAt: '2026-09-20 03:00:00'); // 4 days → still shown
    watchVerdict($site, $old, 'PASS', '2026-09-24 03:00:00', indexedAt: '2026-09-18 03:00:00');   // 6 days → gone
    watchVerdict($site, $unstamped, 'PASS', '2026-09-24 03:00:00');                              // no date → long indexed → gone

    $titles = collect(app(IndexWatchlist::class)->for($site)['rows'])->pluck('title')->all();

    expect($titles)->toBe(['Fresh']);
});

it('counts a page earning impressions as indexed from its first impression, even with no PASS verdict', function () {
    $site = watchSite();
    $page = watchPage($site, 'Seen In Search', '2026-09-10 09:00:00', 'seen');
    watchVerdict($site, $page, IndexCoverageState::CrawledNotIndexed->value, '2026-09-12 03:00:00'); // a stale "not indexed"
    foreach (['2026-09-21', '2026-09-23'] as $date) {
        GscUrlDaily::create(['site_id' => $site->id, 'grain_hash' => hash('sha256', $site->id.'|'.$date.'|seen'), 'date' => $date, 'url' => 'https://watch.example/seen/', 'impressions' => 3, 'clicks' => 0, 'ctr' => 0]);
    }

    $rows = collect(app(IndexWatchlist::class)->for($site)['rows'])->keyBy('title');

    expect($rows['Seen In Search']['state'])->toBe('indexed')
        ->and($rows['Seen In Search']['indexed_at'])->toBe('2026-09-21');
});

it('renders the watchlist block on the Indexing board, and an empty state when nothing is waiting', function () {
    Filament::setCurrentPanel('admin');
    $this->actingAs(User::factory()->create(['role' => UserRole::Operator]));
    $site = watchSite();
    $page = watchPage($site, 'Waiting Town', '2026-09-18 09:00:00', 'waiting-town');
    watchVerdict($site, $page, IndexCoverageState::DiscoveredNotIndexed->value, '2026-09-23 03:00:00');
    app(ActiveTenant::class)->set($site->id);

    $html = Livewire::test(IndexingBoard::class)->assertOk()->html();
    expect($html)->toContain('Waiting on Google')
        ->toContain('Waiting Town')
        ->toContain('Discovered — not indexed')
        ->toContain('18 Sep 2026')
        ->toContain('is-inspected');

    PageIndexState::withoutGlobalScopes()->update(['index_verdict' => 'PASS', 'indexed_at' => '2026-09-10 00:00:00']); // long indexed → off the list
    app()->instance(IndexInspector::class, watchInspector(true));
    expect(Livewire::test(IndexingBoard::class)->html())->toContain('Nothing waiting');
});

it('lists a published post — which has no page type — without failing', function () {
    $site = watchSite();
    Content::factory()->create([
        'site_id' => $site->id, 'kind' => ContentKind::Post, 'page_type' => null, 'status' => ContentStatus::Published,
        'title' => 'A Blog Post', 'slug' => 'a-blog-post', 'published_at' => '2026-09-22 09:00:00', 'wp_post_id' => 7,
    ]);

    $rows = collect(app(IndexWatchlist::class)->for($site)['rows'])->keyBy('title');

    expect($rows['A Blog Post']['kind'])->toBe('post')
        ->and($rows['A Blog Post']['state'])->toBe('published');
});

it('sorts by published, inspected, status or indexed date, either direction, undated rows last', function () {
    $site = watchSite();
    $a = watchPage($site, 'A Plain', '2026-09-20 09:00:00', 'a');
    $b = watchPage($site, 'B Amber', '2026-09-10 09:00:00', 'b');
    $c = watchPage($site, 'C Green', '2026-09-15 09:00:00', 'c');
    watchVerdict($site, $b, IndexCoverageState::CrawledNotIndexed->value, '2026-09-23 03:00:00');
    watchVerdict($site, $c, 'PASS', '2026-09-21 03:00:00', indexedAt: '2026-09-22 03:00:00');
    $titles = fn (string $sort, string $dir = 'asc'): array => collect(app(IndexWatchlist::class)->for($site, $sort, $dir)['rows'])->pluck('title')->all();

    expect($titles('status'))->toBe(['A Plain', 'B Amber', 'C Green'])
        ->and($titles('status', 'desc'))->toBe(['C Green', 'B Amber', 'A Plain'])
        ->and($titles('published'))->toBe(['B Amber', 'C Green', 'A Plain'])
        ->and($titles('published', 'desc'))->toBe(['A Plain', 'C Green', 'B Amber'])
        ->and($titles('inspected'))->toBe(['C Green', 'B Amber', 'A Plain'])        // A never inspected → last
        ->and($titles('inspected', 'desc'))->toBe(['B Amber', 'C Green', 'A Plain']) // still last when flipped
        ->and($titles('indexed'))->toBe(['C Green', 'A Plain', 'B Amber'])           // only C has an index date
        ->and($titles('bogus'))->toBe(['A Plain', 'B Amber', 'C Green']);            // unknown sort → status
});

it('clicking a column header sorts by it and clicking again flips the direction', function () {
    Filament::setCurrentPanel('admin');
    $this->actingAs(User::factory()->create(['role' => UserRole::Operator]));
    $site = watchSite();
    watchPage($site, 'Older', '2026-09-10 09:00:00', 'older');
    watchPage($site, 'Newer', '2026-09-20 09:00:00', 'newer');
    app(ActiveTenant::class)->set($site->id);

    $page = Livewire::test(IndexingBoard::class)
        ->call('sortWatch', 'published')
        ->assertSet('watchSort', 'published')->assertSet('watchDir', 'asc');
    expect(collect($page->instance()->watchlist['rows'])->pluck('title')->all())->toBe(['Older', 'Newer']);

    $page->call('sortWatch', 'published')->assertSet('watchDir', 'desc');
    expect(collect($page->instance()->watchlist['rows'])->pluck('title')->all())->toBe(['Newer', 'Older']);

    $page->call('sortWatch', 'nonsense')->assertSet('watchSort', 'published'); // ignored
    $page->assertSee('Published ▼');
});

it('shows the watchlist on a fresh tenant with published pages but no index data yet', function () {
    Filament::setCurrentPanel('admin');
    $this->actingAs(User::factory()->create(['role' => UserRole::Operator]));
    $site = watchSite();
    watchPage($site, 'Brand New Town', '2026-09-23 09:00:00', 'brand-new-town');
    app(ActiveTenant::class)->set($site->id);

    $html = Livewire::test(IndexingBoard::class)->assertOk()->html();

    expect($html)->toContain('No index data yet')   // the coverage panels' honest empty state stays
        ->toContain('Waiting on Google')            // …and the watchlist still lists what is waiting
        ->toContain('Brand New Town')
        ->toContain('is-published');
});

/** An inspector that reports the connection state a test needs, without touching Google. */
function watchInspector(bool $connected): IndexInspector
{
    return new class($connected) implements IndexInspector
    {
        public function __construct(public bool $isConnected) {}

        public function connected(Site $site): bool
        {
            return $this->isConnected;
        }

        public function inspect(Site $site, string $url): ?IndexStatus
        {
            return null;
        }

        public function cached(Site $site, string $url): ?IndexStatus
        {
            return null;
        }
    };
}

it('says so when the site is on a test domain or has no Search Console connection — no data is expected', function () {
    $connected = Site::factory()->create(['domain_url' => 'https://www.sandhogworks.com', 'gsc_property' => 'sc-domain:sandhogworks.com']);
    $staging = Site::factory()->create(['domain_url' => 'https://miller-tire-auto-l6nxxj.flywp.xyz/']);
    $noGsc = Site::factory()->create(['domain_url' => 'https://plumbers.example', 'gsc_property' => null]);

    app()->instance(IndexInspector::class, watchInspector(true));
    expect(app(IndexWatchlist::class)->readiness($connected))->toBe(['connected' => true, 'test_domain' => false, 'host' => 'www.sandhogworks.com'])
        ->and(app(IndexWatchlist::class)->readiness($staging))->toBe(['connected' => true, 'test_domain' => true, 'host' => 'miller-tire-auto-l6nxxj.flywp.xyz']);

    app()->instance(IndexInspector::class, watchInspector(false));
    expect(app(IndexWatchlist::class)->readiness($noGsc)['connected'])->toBeFalse();
});

it('renders the test-domain and not-connected notes on the board, and neither when connected on a real domain', function () {
    Filament::setCurrentPanel('admin');
    $this->actingAs(User::factory()->create(['role' => UserRole::Operator]));

    $staging = Site::factory()->create(['domain_url' => 'https://miller-tire-auto-l6nxxj.flywp.xyz/']);
    watchPage($staging, 'Perkasie', '2026-09-23 09:00:00', 'perkasie');
    app(ActiveTenant::class)->set($staging->id);
    app()->instance(IndexInspector::class, watchInspector(false));
    $html = Livewire::test(IndexingBoard::class)->assertOk()->html();
    expect($html)->toContain('Test domain — nothing here can be indexed')
        ->toContain('flywp.xyz')
        ->toContain('and Search Console is connected')
        ->toContain('Perkasie');

    $real = Site::factory()->create(['domain_url' => 'https://plumbers.example']);
    app(ActiveTenant::class)->set($real->id);
    $html = Livewire::test(IndexingBoard::class)->assertOk()->html();
    expect($html)->toContain('Search Console is not connected')
        ->toContain('Nothing on the list')
        ->not->toContain('Test domain');

    app()->instance(IndexInspector::class, watchInspector(true));
    $html = Livewire::test(IndexingBoard::class)->assertOk()->html();
    expect($html)->not->toContain('Search Console is not connected')
        ->toContain('Nothing waiting — every published page is indexed.');
});
