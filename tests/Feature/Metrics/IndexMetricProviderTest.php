<?php

use App\Enums\ContentKind;
use App\Enums\ContentStatus;
use App\Enums\IndexCoverageState;
use App\Enums\PageType;
use App\Integrations\UrlInspection\IndexInspector;
use App\Integrations\UrlInspection\IndexStatus;
use App\Jobs\SyncSiteMetrics;
use App\Metrics\MetricProviderRegistry;
use App\Metrics\Providers\IndexMetricProvider;
use App\Metrics\UrlNormalizer;
use App\Models\Content;
use App\Models\GscUrlDaily;
use App\Models\Job;
use App\Models\MetricSnapshot;
use App\Models\PageIndexState;
use App\Models\Site;
use App\Operator\IndexCoverage;
use App\Support\CurrentSite;
use App\Support\PublicUrl;
use Carbon\CarbonPeriod;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;

afterEach(function () {
    CurrentSite::clear();
});

/**
 * A fake URL inspector: connected, returns whatever IndexStatus is mapped for a URL (null otherwise, which
 * models a quota miss / never-crawled URL).
 */
function fakeInspector(bool $connected = true): IndexInspector
{
    return new class($connected) implements IndexInspector
    {
        /** @var array<string, IndexStatus> */
        public array $verdicts = [];

        public function __construct(public bool $isConnected) {}

        public function connected(Site $site): bool
        {
            return $this->isConnected;
        }

        public function inspect(Site $site, string $url): ?IndexStatus
        {
            return $this->verdicts[$url] ?? null;
        }

        public function cached(Site $site, string $url): ?IndexStatus
        {
            return $this->verdicts[$url] ?? null;
        }
    };
}

function indexStatus(string $url, IndexCoverageState $state): IndexStatus
{
    return new IndexStatus(url: $url, state: $state, coverageState: $state->label(), verdict: $state->indexed() ? 'PASS' : 'NEUTRAL');
}

function providerWith(IndexInspector $inspector): IndexMetricProvider
{
    return new IndexMetricProvider(new IndexCoverage($inspector));
}

it('persists per-URL verdicts durably and stamps the daily pages-indexed snapshot', function () {
    $site = Site::factory()->create(['domain_url' => 'https://apex.example']);
    $indexedPage = Content::factory()->create(['site_id' => $site->id, 'kind' => ContentKind::Page, 'page_type' => PageType::Service, 'status' => ContentStatus::Published, 'wp_post_id' => 1, 'slug' => 'indexed-page', 'title' => 'Indexed']);
    $pendingPage = Content::factory()->create(['site_id' => $site->id, 'kind' => ContentKind::Page, 'page_type' => PageType::Service, 'status' => ContentStatus::Published, 'wp_post_id' => 2, 'slug' => 'pending-page', 'title' => 'Pending']);
    $job = Job::factory()->published()->create(['site_id' => $site->id, 'post_title' => 'Sump Pump Job']);

    $urlIndexed = PublicUrl::forContent($site->domain_url, $indexedPage);
    $urlPending = PublicUrl::forContent($site->domain_url, $pendingPage);
    $urlJob = $job->publicUrl($site->domain_url);

    $inspector = fakeInspector();
    $inspector->verdicts = [
        $urlIndexed => indexStatus($urlIndexed, IndexCoverageState::Indexed),
        $urlPending => indexStatus($urlPending, IndexCoverageState::CrawledNotIndexed),
        $urlJob => indexStatus($urlJob, IndexCoverageState::Indexed),
    ];

    $result = providerWith($inspector)->sync($site, CarbonPeriod::create('2026-08-10', '2026-08-10'));
    expect($result->status)->toBe('success')->and($result->rowsWritten)->toBe(3);

    // Durable rows, one per URL.
    expect(PageIndexState::withoutGlobalScopes()->where('site_id', $site->id)->count())->toBe(3);

    $rowIndexed = PageIndexState::withoutGlobalScopes()->where('content_id', $indexedPage->id)->first();
    expect($rowIndexed->index_verdict)->toBe('PASS')
        ->and($rowIndexed->isIndexed())->toBeTrue()
        ->and($rowIndexed->url_normalized)->toBe(UrlNormalizer::url($urlIndexed));

    expect(PageIndexState::withoutGlobalScopes()->where('content_id', $pendingPage->id)->first()->index_verdict)->toBe('crawled_not_indexed');

    // A job is not a Content row — content_id stays null.
    $jobRow = PageIndexState::withoutGlobalScopes()->where('url_normalized', UrlNormalizer::url($urlJob))->first();
    expect($jobRow->content_id)->toBeNull()->and($jobRow->index_verdict)->toBe('PASS')->and($jobRow->origin)->toBe('job')
        ->and($rowIndexed->origin)->toBe('content');

    // Daily site snapshot, read from the durable table: 2 of 3 indexed.
    $today = now()->toDateString();
    $snap = fn (string $key) => MetricSnapshot::withoutGlobalScopes()->where('site_id', $site->id)
        ->where('provider', 'index')->where('metric_key', $key)->where('period_date', $today)->value('value_numeric');
    expect((int) $snap('pages_indexed'))->toBe(2)
        ->and((int) $snap('pages_known'))->toBe(3)
        ->and((int) $snap('all_known_indexed'))->toBe(2)
        ->and((int) $snap('all_known_pages'))->toBe(3);
});

function legacyGscUrl(Site $site, string $url, int $impressions): void
{
    GscUrlDaily::withoutGlobalScopes()->create([
        'id' => (string) Str::ulid(), 'site_id' => $site->id, 'grain_hash' => Str::random(32),
        'date' => now()->subDays(3)->toDateString(), 'url' => $url,
        'impressions' => $impressions, 'clicks' => 0, 'position' => 9.0,
    ]);
}

it('stores the legacy URLs Google has shown as discovered rows, outside the submitted trend', function () {
    config()->set('launchpad.indexing.all_known_capture', true);
    $site = Site::factory()->create(['domain_url' => 'https://apex.example']);
    $page = Content::factory()->create(['site_id' => $site->id, 'kind' => ContentKind::Page, 'page_type' => PageType::Service, 'status' => ContentStatus::Published, 'wp_post_id' => 1, 'slug' => 'p', 'title' => 'P']);
    $url = PublicUrl::forContent($site->domain_url, $page);
    $legacy = 'https://apex.example/how-to-install-a-sump-pump-correctly-2/';
    legacyGscUrl($site, $url, 100);
    legacyGscUrl($site, $legacy, 87_949);

    $inspector = fakeInspector();
    $inspector->verdicts = [
        $url => indexStatus($url, IndexCoverageState::CrawledNotIndexed),
        $legacy => indexStatus($legacy, IndexCoverageState::Indexed),
    ];
    $result = providerWith($inspector)->sync($site, CarbonPeriod::create('2026-08-10', '2026-08-10'));

    expect($result->rowsWritten)->toBe(2);
    $row = PageIndexState::withoutGlobalScopes()->where('url_normalized', UrlNormalizer::url($legacy))->first();
    expect($row->origin)->toBe('discovered')
        ->and($row->content_id)->toBeNull()
        ->and($row->index_verdict)->toBe('PASS')
        ->and($row->indexed_at)->not->toBeNull();

    // The client-facing trend is the SUBMITTED set: 0 of 1 — the legacy page must not inflate it.
    $today = now()->toDateString();
    $snap = fn (string $key) => MetricSnapshot::withoutGlobalScopes()->where('site_id', $site->id)
        ->where('provider', 'index')->where('metric_key', $key)->where('period_date', $today)->value('value_numeric');
    expect((int) $snap('pages_indexed'))->toBe(0)
        ->and((int) $snap('pages_known'))->toBe(1)
        ->and((int) $snap('all_known_indexed'))->toBe(1)
        ->and((int) $snap('all_known_pages'))->toBe(2);
});

it('stamps last_inspected_at with the time Google answered, not the sync time', function () {
    $site = Site::factory()->create(['domain_url' => 'https://apex.example']);
    $page = Content::factory()->create(['site_id' => $site->id, 'kind' => ContentKind::Page, 'page_type' => PageType::Service, 'status' => ContentStatus::Published, 'wp_post_id' => 1, 'slug' => 'p', 'title' => 'P']);
    $url = PublicUrl::forContent($site->domain_url, $page);
    $answered = now()->subDays(9)->startOfMinute();

    $inspector = fakeInspector();
    $inspector->verdicts = [$url => new IndexStatus(url: $url, state: IndexCoverageState::Indexed, coverageState: 'Submitted and indexed', verdict: 'PASS', inspectedAt: $answered)];
    providerWith($inspector)->sync($site, CarbonPeriod::create('2026-08-10', '2026-08-10'));

    // A verdict served from the inspector's cache keeps its fetch time — the board's "as of" stays honest.
    expect(PageIndexState::withoutGlobalScopes()->where('content_id', $page->id)->first()->last_inspected_at->toIso8601String())
        ->toBe($answered->toIso8601String());
});

it('is idempotent — a re-run updates rows in place, never duplicates', function () {
    $site = Site::factory()->create(['domain_url' => 'https://apex.example']);
    $page = Content::factory()->create(['site_id' => $site->id, 'kind' => ContentKind::Page, 'page_type' => PageType::Service, 'status' => ContentStatus::Published, 'wp_post_id' => 1, 'slug' => 'p', 'title' => 'P']);
    $url = PublicUrl::forContent($site->domain_url, $page);

    $inspector = fakeInspector();
    $inspector->verdicts = [$url => indexStatus($url, IndexCoverageState::CrawledNotIndexed)];
    $provider = providerWith($inspector);

    $provider->sync($site, CarbonPeriod::create('2026-08-10', '2026-08-10'));

    // Google indexes it on the next run.
    $inspector->verdicts = [$url => indexStatus($url, IndexCoverageState::Indexed)];
    $provider->sync($site, CarbonPeriod::create('2026-08-10', '2026-08-10'));

    expect(PageIndexState::withoutGlobalScopes()->where('site_id', $site->id)->count())->toBe(1)
        ->and(PageIndexState::withoutGlobalScopes()->where('content_id', $page->id)->first()->index_verdict)->toBe('PASS');
});

it('does not clear a prior verdict when a URL is not inspected this run (quota)', function () {
    $site = Site::factory()->create(['domain_url' => 'https://apex.example']);
    $page = Content::factory()->create(['site_id' => $site->id, 'kind' => ContentKind::Page, 'page_type' => PageType::Service, 'status' => ContentStatus::Published, 'wp_post_id' => 1, 'slug' => 'p', 'title' => 'P']);
    $url = PublicUrl::forContent($site->domain_url, $page);

    $inspector = fakeInspector();
    $inspector->verdicts = [$url => indexStatus($url, IndexCoverageState::Indexed)];
    providerWith($inspector)->sync($site, CarbonPeriod::create('2026-08-10', '2026-08-10'));

    // Next run: quota exhausted → inspect() returns null (not_inspected). The durable PASS must survive.
    $inspector->verdicts = [];
    providerWith($inspector)->sync($site, CarbonPeriod::create('2026-08-11', '2026-08-11'));

    expect(PageIndexState::withoutGlobalScopes()->where('content_id', $page->id)->first()->index_verdict)->toBe('PASS');
});

it('prunes a stale orphan row when a content re-inspects at a new canonical URL', function () {
    // A home page's URL canonicalizes /home/ → root; the old row would otherwise linger at /home/ with a
    // frozen excluded_redirect verdict and double-count the content. Prune-on-sync must heal it.
    $site = Site::factory()->create(['domain_url' => 'https://apex.example']);
    $home = Content::factory()->create(['site_id' => $site->id, 'kind' => ContentKind::Page, 'page_type' => PageType::Home, 'status' => ContentStatus::Published, 'wp_post_id' => 1, 'slug' => 'home', 'title' => 'Home']);

    $canonical = PublicUrl::forContent($site->domain_url, $home); // → https://apex.example/ (root, not /home/)
    $staleUrl = 'https://apex.example/home/';

    // Seed the stale orphan row at /home/ (what a prior, pre-fix sync recorded).
    DB::table('page_index_states')->insert([
        'id' => (string) Str::ulid(),
        'site_id' => $site->id,
        'content_id' => $home->id,
        'url' => $staleUrl,
        'url_normalized' => UrlNormalizer::url($staleUrl),
        'coverage_state' => 'Page with redirect',
        'index_verdict' => 'excluded_redirect',
        'canonical_url' => null,
        'last_inspected_at' => now()->subDays(3),
        'created_at' => now()->subDays(3),
        'updated_at' => now()->subDays(3),
    ]);

    $inspector = fakeInspector();
    $inspector->verdicts = [$canonical => indexStatus($canonical, IndexCoverageState::Indexed)];
    providerWith($inspector)->sync($site, CarbonPeriod::create('2026-08-12', '2026-08-12'));

    // Exactly one row for the content — its canonical URL — and the stale /home/ orphan is gone.
    $rows = PageIndexState::withoutGlobalScopes()->where('content_id', $home->id)->get();
    expect($rows)->toHaveCount(1)
        ->and($rows->first()->url_normalized)->toBe(UrlNormalizer::url($canonical))
        ->and($rows->first()->index_verdict)->toBe('PASS')
        ->and(PageIndexState::withoutGlobalScopes()->where('url_normalized', UrlNormalizer::url($staleUrl))->exists())->toBeFalse();
});

it('is a clean no-op when Search Console is not connected', function () {
    $site = Site::factory()->create(['domain_url' => 'https://apex.example']);
    Content::factory()->create(['site_id' => $site->id, 'kind' => ContentKind::Page, 'page_type' => PageType::Service, 'status' => ContentStatus::Published, 'wp_post_id' => 1, 'slug' => 'p', 'title' => 'P']);

    $result = providerWith(fakeInspector(connected: false))->sync($site, CarbonPeriod::create('2026-08-10', '2026-08-10'));

    expect($result->rowsWritten)->toBe(0)
        ->and(PageIndexState::withoutGlobalScopes()->count())->toBe(0)
        ->and(MetricSnapshot::withoutGlobalScopes()->count())->toBe(0);
});

it('registers index in the provider registry', function () {
    $registry = app(MetricProviderRegistry::class);

    expect($registry->has('index'))->toBeTrue()
        ->and($registry->get('index'))->toBeInstanceOf(IndexMetricProvider::class);
});

it('sync-index dispatches an index sync per site', function () {
    Queue::fake();
    $site = Site::factory()->create(['domain_url' => 'https://apex.example']);

    $this->artisan('sandhog:sync-index', ['site' => $site->id])->assertSuccessful();

    Queue::assertPushed(SyncSiteMetrics::class, fn (SyncSiteMetrics $j): bool => $j->provider === 'index' && $j->siteId === $site->id && $j->queue === 'metrics:index');
});

it('stamps indexed_at the first time a URL reads PASS, keeps it across re-inspections, and clears it when the URL drops out', function () {
    $site = Site::factory()->create(['domain_url' => 'https://apex.example']);
    $page = Content::factory()->create(['site_id' => $site->id, 'kind' => ContentKind::Page, 'page_type' => PageType::Service, 'status' => ContentStatus::Published, 'wp_post_id' => 1, 'slug' => 'stamped', 'title' => 'Stamped']);
    $url = PublicUrl::forContent($site->domain_url, $page);
    $inspector = fakeInspector();
    $row = fn () => PageIndexState::withoutGlobalScopes()->where('content_id', $page->id)->first();

    // Not indexed yet → no stamp.
    $inspector->verdicts = [$url => indexStatus($url, IndexCoverageState::CrawledNotIndexed)];
    $this->travelTo('2026-09-20 03:00:00');
    providerWith($inspector)->sync($site, CarbonPeriod::create('2026-09-20', '2026-09-20'));
    expect($row()->indexed_at)->toBeNull();

    // First PASS → stamped now.
    $inspector->verdicts = [$url => indexStatus($url, IndexCoverageState::Indexed)];
    $this->travelTo('2026-09-21 03:00:00');
    providerWith($inspector)->sync($site, CarbonPeriod::create('2026-09-21', '2026-09-21'));
    expect($row()->indexed_at?->toDateTimeString())->toBe('2026-09-21 03:00:00');

    // Re-inspected still PASS → the stamp does not move (last_inspected_at does).
    $this->travelTo('2026-09-23 03:00:00');
    providerWith($inspector)->sync($site, CarbonPeriod::create('2026-09-23', '2026-09-23'));
    expect($row()->indexed_at?->toDateTimeString())->toBe('2026-09-21 03:00:00')
        ->and($row()->last_inspected_at?->toDateTimeString())->toBe('2026-09-23 03:00:00');

    // Dropped out → cleared, so a return re-stamps.
    $inspector->verdicts = [$url => indexStatus($url, IndexCoverageState::CrawledNotIndexed)];
    $this->travelTo('2026-09-24 03:00:00');
    providerWith($inspector)->sync($site, CarbonPeriod::create('2026-09-24', '2026-09-24'));
    expect($row()->indexed_at)->toBeNull();
});
