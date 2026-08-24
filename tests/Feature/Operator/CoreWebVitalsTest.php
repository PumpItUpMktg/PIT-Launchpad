<?php

use App\Enums\ContentKind;
use App\Enums\ContentStatus;
use App\Enums\PageType;
use App\Integrations\PageSpeed\PageSpeedProvider;
use App\Integrations\PageSpeed\PageVitals;
use App\Metrics\UrlNormalizer;
use App\Models\Content;
use App\Models\PageVital;
use App\Models\Site;
use App\Operator\CoreWebVitalsAudit;
use App\Support\PublicUrl;
use Illuminate\Support\Facades\Http;

/** A deterministic PageSpeed source keyed by URL — no HTTP. */
function fakePsi(array $byUrl, bool $enabled = true): PageSpeedProvider
{
    return new class($byUrl, $enabled) implements PageSpeedProvider
    {
        public function __construct(private array $byUrl, private bool $enabled) {}

        public function enabled(): bool
        {
            return $this->enabled;
        }

        public function measure(string $url, string $strategy = 'mobile'): ?PageVitals
        {
            return $this->byUrl[$url] ?? null;
        }
    };
}

function vpage(Site $site, string $slug): Content
{
    return Content::factory()->create([
        'site_id' => $site->id, 'kind' => ContentKind::Page, 'page_type' => PageType::Service,
        'status' => ContentStatus::Published, 'wp_post_id' => 1, 'slug' => $slug, 'title' => mb_strtoupper($slug),
    ]);
}

it('measures published pages and stores durable vitals readings', function () {
    $site = Site::factory()->create(['domain_url' => 'https://spg.example']);
    $a = vpage($site, 'sump-pump-repair');
    $b = vpage($site, 'sump-pump-maintenance');

    $urlA = PublicUrl::forContent($site->domain_url, $a);
    $urlB = PublicUrl::forContent($site->domain_url, $b);

    $r = (new CoreWebVitalsAudit(fakePsi([
        $urlA => new PageVitals($urlA, 'mobile', 88, 2100, 0.04, 150),
        $urlB => new PageVitals($urlB, 'mobile', 42, 4200, 0.28, 420),
    ])))->audit($site);

    expect($r)->toMatchArray(['enabled' => true, 'total' => 2, 'measured' => 2, 'skipped_fresh' => 0, 'deferred' => 0]);

    $rowA = PageVital::withoutGlobalScopes()->where('content_id', $a->id)->first();
    expect($rowA->performance_score)->toBe(88)
        ->and($rowA->lcp_ms)->toBe(2100)
        ->and((float) $rowA->cls)->toBe(0.04)
        ->and($rowA->passesCoreWebVitals())->toBeTrue()
        ->and(PageVital::withoutGlobalScopes()->where('content_id', $b->id)->first()->passesCoreWebVitals())->toBeFalse();
});

it('skips a URL whose stored reading is still fresh', function () {
    $site = Site::factory()->create(['domain_url' => 'https://spg.example']);
    $a = vpage($site, 'fresh-page');
    $url = PublicUrl::forContent($site->domain_url, $a);

    PageVital::withoutGlobalScopes()->create([
        'site_id' => $site->id, 'content_id' => $a->id, 'url' => $url, 'url_normalized' => UrlNormalizer::url($url),
        'strategy' => 'mobile', 'performance_score' => 70, 'lcp_ms' => 2000, 'cls' => 0.05, 'measured_at' => now()->subDay(),
    ]);

    // The fake has NO reading for this URL — so if the audit tried to measure, it would find none.
    $r = (new CoreWebVitalsAudit(fakePsi([])))->audit($site, freshnessDays: 7);

    expect($r['skipped_fresh'])->toBe(1)
        ->and($r['measured'])->toBe(0)
        ->and(PageVital::withoutGlobalScopes()->where('content_id', $a->id)->first()->performance_score)->toBe(70); // untouched
});

it('defers remaining URLs once the wall-clock budget is spent', function () {
    $site = Site::factory()->create(['domain_url' => 'https://spg.example']);
    vpage($site, 'p1');
    vpage($site, 'p2');

    $r = (new CoreWebVitalsAudit(fakePsi([])))->audit($site, budgetSeconds: 0.0);

    expect($r['measured'])->toBe(0)->and($r['deferred'])->toBe(2)
        ->and(PageVital::withoutGlobalScopes()->count())->toBe(0);
});

it('is a clean no-op when PageSpeed is disabled', function () {
    $site = Site::factory()->create(['domain_url' => 'https://spg.example']);
    vpage($site, 'p1');

    expect((new CoreWebVitalsAudit(fakePsi([], enabled: false)))->audit($site)['enabled'])->toBeFalse()
        ->and(PageVital::withoutGlobalScopes()->count())->toBe(0);
});

it('the PageSpeed adapter parses a Lighthouse result and degrades to null on error', function () {
    Http::fake([
        '*good*' => Http::response(['lighthouseResult' => [
            'categories' => ['performance' => ['score' => 0.83]],
            'audits' => [
                'largest-contentful-paint' => ['numericValue' => 2100.6],
                'cumulative-layout-shift' => ['numericValue' => 0.05],
                'interaction-to-next-paint' => ['numericValue' => 180],
            ],
        ]]),
        '*fail*' => Http::response('', 500),
    ]);

    $psi = app(PageSpeedProvider::class);
    $good = $psi->measure('https://spg.example/good/');
    expect($good->performanceScore)->toBe(83)->and($good->lcpMs)->toBe(2101)->and($good->inpMs)->toBe(180);
    expect($psi->measure('https://spg.example/fail/'))->toBeNull();
});

it('the sync-vitals command runs for a site', function () {
    $site = Site::factory()->create(['domain_url' => 'https://spg.example', 'brand_name' => 'SPG']);
    $a = vpage($site, 'p1');
    $url = PublicUrl::forContent($site->domain_url, $a);
    app()->instance(CoreWebVitalsAudit::class, new CoreWebVitalsAudit(fakePsi([
        $url => new PageVitals($url, 'mobile', 91, 1800, 0.02, 120),
    ])));

    $this->artisan('sandhog:sync-vitals', ['site' => 'SPG'])
        ->expectsOutputToContain('1 measured')
        ->assertSuccessful();
});
