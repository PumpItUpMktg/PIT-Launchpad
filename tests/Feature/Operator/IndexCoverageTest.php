<?php

use App\Enums\ContentKind;
use App\Enums\ContentStatus;
use App\Enums\IndexCoverageState;
use App\Integrations\UrlInspection\IndexInspector;
use App\Integrations\UrlInspection\IndexStatus;
use App\Models\Content;
use App\Models\Job;
use App\Models\PageIndexState;
use App\Models\Site;
use App\Models\User;
use App\Operator\IndexCoverage;

/** A deterministic IndexInspector keyed by URL — no HTTP. */
function bindFakeInspector(array $byUrl, bool $connected = true): void
{
    $fake = new class($byUrl, $connected) implements IndexInspector
    {
        public function __construct(private array $byUrl, private bool $connected) {}

        public function connected(Site $site): bool
        {
            return $this->connected;
        }

        public function inspect(Site $site, string $url): ?IndexStatus
        {
            return $this->byUrl[$url] ?? null;
        }

        public function cached(Site $site, string $url): ?IndexStatus
        {
            return $this->byUrl[$url] ?? null;
        }
    };
    app()->instance(IndexInspector::class, $fake);
}

function status(string $url, IndexCoverageState $state, string $coverage = ''): IndexStatus
{
    return new IndexStatus(url: $url, state: $state, coverageState: $coverage ?: $state->label());
}

it('includes published jobs in the audit so job pages get a real index verdict', function () {
    $site = Site::factory()->create(['domain_url' => 'https://spg.example']);
    $job = Job::factory()->published()->create(['site_id' => $site->id, 'post_title' => 'Sump Pump Replacement'])->fresh();
    $url = (string) $job->publicUrl('https://spg.example');

    bindFakeInspector([$url => status($url, IndexCoverageState::Indexed)]);

    $finding = collect(app(IndexCoverage::class)->audit($site)['findings'])->firstWhere('content_id', $job->id);

    expect($finding)->not->toBeNull()
        ->and($finding['kind'])->toBe('job')
        ->and($finding['url'])->toBe($url)      // {domain}/jobs/{slug}/ — the trailing-slash form the cards read
        ->and($finding['indexed'])->toBeTrue();
});

it('tallies real index coverage across published pages and posts', function () {
    $site = Site::factory()->create(['domain_url' => 'https://spg.example']);
    $mk = fn (string $slug, ContentKind $kind) => Content::factory()->create([
        'site_id' => $site->id, 'kind' => $kind, 'status' => ContentStatus::Published, 'wp_post_id' => 1, 'slug' => $slug, 'title' => $slug,
    ]);
    $mk('hoboken-nj', ContentKind::Page);
    $mk('clifton-nj', ContentKind::Page);
    $mk('a-blog-post', ContentKind::Post);

    bindFakeInspector([
        'https://spg.example/hoboken-nj/' => status('https://spg.example/hoboken-nj/', IndexCoverageState::Indexed),
        'https://spg.example/clifton-nj/' => status('https://spg.example/clifton-nj/', IndexCoverageState::CrawledNotIndexed),
        'https://spg.example/a-blog-post/' => status('https://spg.example/a-blog-post/', IndexCoverageState::Indexed),
    ]);

    $r = app(IndexCoverage::class)->audit($site, live: true);

    expect($r['connected'])->toBeTrue()
        ->and($r['total'])->toBe(3)
        ->and($r['indexed'])->toBe(2)
        ->and($r['inspected'])->toBe(3)
        ->and($r['by_state']['indexed'])->toBe(2)
        ->and($r['by_state']['crawled_not_indexed'])->toBe(1)
        // Inspects the trailing-slash permalink (same URL the Live cards read) — not the redirecting form.
        ->and(collect($r['findings'])->pluck('url')->every(fn (string $u): bool => str_ends_with($u, '/')))->toBeTrue();
});

it('audits every published page for the site, ignoring the actor tenant-visibility scope', function () {
    $site = Site::factory()->create(['domain_url' => 'https://spg.example']);
    Content::factory()->create(['site_id' => $site->id, 'kind' => ContentKind::Page, 'status' => ContentStatus::Published, 'wp_post_id' => 1, 'slug' => 'hoboken-nj', 'title' => 'Hoboken']);

    // A restricted actor (Site Admin, no memberships) → VisibleTenantScope hides ALL content in cross-tenant
    // mode. This is what made the audit report 22 of 407 in production.
    $this->actingAs(User::factory()->siteAdmin()->create());
    expect(Content::where('site_id', $site->id)->count())->toBe(0); // a normally-scoped query sees nothing

    bindFakeInspector(['https://spg.example/hoboken-nj/' => status('https://spg.example/hoboken-nj/', IndexCoverageState::Indexed)]);

    // audit() strips ALL global scopes (it filters by site_id itself), so it still sees the site's page.
    expect(app(IndexCoverage::class)->audit($site)['total'])->toBe(1);
});

it('respects the live-inspection time budget, falling back to cached verdicts once spent', function () {
    $site = Site::factory()->create(['domain_url' => 'https://spg.example']);
    Content::factory()->create(['site_id' => $site->id, 'kind' => ContentKind::Page, 'status' => ContentStatus::Published, 'wp_post_id' => 1, 'slug' => 'x', 'title' => 'X']);

    // A fake where a LIVE inspect() reports Indexed but nothing is cached yet — so we can tell which path ran.
    app()->instance(IndexInspector::class, new class implements IndexInspector
    {
        public function connected(Site $site): bool
        {
            return true;
        }

        public function inspect(Site $site, string $url): ?IndexStatus
        {
            return new IndexStatus(url: $url, state: IndexCoverageState::Indexed, coverageState: 'Indexed');
        }

        public function cached(Site $site, string $url): ?IndexStatus
        {
            return null;
        }
    });

    // Budget already spent (0s) → uses cached() → nothing inspected.
    $spent = app(IndexCoverage::class)->audit($site, live: true, liveBudgetSeconds: 0.0);
    expect($spent['inspected'])->toBe(0)->and($spent['not_inspected'])->toBe(1);

    // No budget → live inspection runs.
    $full = app(IndexCoverage::class)->audit($site, live: true);
    expect($full['inspected'])->toBe(1)->and($full['indexed'])->toBe(1);
});

it('inspects uninspected pages first, then the stalest verdicts (not oldest-published)', function () {
    $site = Site::factory()->create(['domain_url' => 'https://spg.example']);
    $mk = fn (string $slug, $publishedAt) => Content::factory()->create([
        'site_id' => $site->id, 'kind' => ContentKind::Page, 'status' => ContentStatus::Published,
        'wp_post_id' => 1, 'slug' => $slug, 'title' => $slug, 'published_at' => $publishedAt,
    ]);
    // Published oldest→newest: a, b, c. Under the OLD published_at order the run would hit a, b, c.
    $a = $mk('a', now()->subDays(10));  // inspected recently (fresh)
    $b = $mk('b', now()->subDays(5));   // inspected long ago (stalest)
    $c = $mk('c', now()->subDay());     // never inspected (newest, the starved tail)

    $fresh = fn (Content $ct, $when) => PageIndexState::create([
        'site_id' => $site->id, 'content_id' => $ct->id,
        'url' => "https://spg.example/{$ct->slug}/", 'url_normalized' => "https://spg.example/{$ct->slug}",
        'index_verdict' => 'PASS', 'coverage_state' => 'indexed', 'last_inspected_at' => $when,
    ]);
    $fresh($a, now()->subDay());
    $fresh($b, now()->subDays(20));
    // c: no page_index_states row → uninspected.

    $rec = new class implements IndexInspector
    {
        /** @var list<string> */
        public array $order = [];

        public function connected(Site $site): bool
        {
            return true;
        }

        public function inspect(Site $site, string $url): ?IndexStatus
        {
            $this->order[] = $url;

            return new IndexStatus(url: $url, state: IndexCoverageState::Indexed, coverageState: 'Indexed');
        }

        public function cached(Site $site, string $url): ?IndexStatus
        {
            $this->order[] = $url;

            return new IndexStatus(url: $url, state: IndexCoverageState::Indexed, coverageState: 'Indexed');
        }
    };
    app()->instance(IndexInspector::class, $rec);

    app(IndexCoverage::class)->audit($site, live: true);

    // Uninspected (c) first, then the stalest verdict (b, 20d), then the fresh one (a, 1d) — NOT a,b,c.
    expect($rec->order)->toBe([
        'https://spg.example/c/',
        'https://spg.example/b/',
        'https://spg.example/a/',
    ]);
});

it('reports nothing fabricated when not connected', function () {
    $site = Site::factory()->create(['domain_url' => 'https://spg.example']);
    Content::factory()->create(['site_id' => $site->id, 'kind' => ContentKind::Page, 'status' => ContentStatus::Published, 'wp_post_id' => 1, 'slug' => 'x', 'title' => 'X']);
    bindFakeInspector([], connected: false);

    $r = app(IndexCoverage::class)->audit($site, live: true);

    expect($r['connected'])->toBeFalse()
        ->and($r['indexed'])->toBe(0)
        ->and($r['not_inspected'])->toBe(1);
});

it('the audit-index command prints the coverage summary', function () {
    $site = Site::factory()->create(['brand_name' => 'SPG', 'domain_url' => 'https://spg.example']);
    Content::factory()->create(['site_id' => $site->id, 'kind' => ContentKind::Page, 'status' => ContentStatus::Published, 'wp_post_id' => 1, 'slug' => 'hoboken-nj', 'title' => 'Hoboken']);
    bindFakeInspector([
        'https://spg.example/hoboken-nj/' => status('https://spg.example/hoboken-nj/', IndexCoverageState::Indexed),
    ]);

    $this->artisan('launchpad:audit-index --site=SPG')
        ->expectsOutputToContain('1 of 1 published URLs indexed')
        ->assertSuccessful();
});
