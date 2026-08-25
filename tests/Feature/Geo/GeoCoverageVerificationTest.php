<?php

use App\Enums\GeoPromptKind;
use App\Geo\GeoCoverage;
use App\Geo\GeoCoveragePromptSeeder;
use App\Geo\GeoCoverageVerification;
use App\Geo\GeoGapBridge;
use App\Models\CoverageArea;
use App\Models\GeoPrompt;
use App\Models\GeoSnapshot;
use App\Models\Scopes\SiteScope;
use App\Models\Service;
use App\Models\Site;
use App\Support\CurrentSite;

afterEach(fn () => CurrentSite::clear());

function verTown(Site $site, string $name, string $tier = 'major', array $locationIds = []): CoverageArea
{
    return CoverageArea::factory()->create(['site_id' => $site->id, 'name' => $name, 'state' => 'NJ', 'size_tier' => $tier, 'population' => 50000, 'page_selected' => true, 'source_location_ids' => $locationIds]);
}

function coveragePrompt(Site $site, Service $svc, CoverageArea $town): GeoPrompt
{
    return GeoPrompt::create([
        'site_id' => $site->id, 'service_id' => $svc->id, 'coverage_area_id' => $town->id, 'size_tier' => $town->size_tier,
        'kind' => GeoPromptKind::Coverage->value, 'prompt' => 'does brand serve '.$town->name, 'active' => true,
    ]);
}

function verSnap(GeoPrompt $p, bool $cited, string $sentiment = 'positive'): void
{
    GeoSnapshot::create(['site_id' => $p->site_id, 'geo_prompt_id' => $p->id, 'engine' => 'claude', 'cited' => $cited, 'sentiment' => $sentiment, 'checked_at' => now()]);
}

it('seeds brand-anchored coverage-check prompts per service × town', function () {
    $site = Site::factory()->create(['brand_name' => 'Sump Pump Gurus', 'domain_url' => 'https://spg.example']);
    $svc = Service::factory()->create(['site_id' => $site->id, 'name' => 'Repair']);
    $town = verTown($site, 'Union');

    $r = app(GeoCoveragePromptSeeder::class)->seed($site);

    expect($r)->toMatchArray(['created' => 1, 'skipped' => 0, 'services' => 1, 'towns' => 1]);

    $p = GeoPrompt::withoutGlobalScope(SiteScope::class)->where('site_id', $site->id)->where('kind', GeoPromptKind::Coverage->value)->sole();
    expect($p->kind)->toBe(GeoPromptKind::Coverage)
        ->and($p->prompt)->toContain('Sump Pump Gurus')
        ->and($p->prompt)->toContain('Union')
        ->and($p->coverage_area_id)->toBe($town->id);

    // Re-seed is idempotent.
    expect(app(GeoCoveragePromptSeeder::class)->seed($site)['created'])->toBe(0);
});

it('requires a brand name to seed coverage checks (they name the business)', function () {
    $site = Site::factory()->create(['brand_name' => '']);
    Service::factory()->create(['site_id' => $site->id]);
    verTown($site, 'Union');

    expect(app(GeoCoveragePromptSeeder::class)->seed($site)['created'])->toBe(0);
});

it('excludes coverage-check prompts from the visibility cited% matrix', function () {
    $site = Site::factory()->create(['brand_name' => 'SPG']);
    $svc = Service::factory()->create(['site_id' => $site->id, 'name' => 'Repair']);
    $town = verTown($site, 'Union');

    // A visibility prompt (default kind) + a coverage prompt, both measured.
    $vis = GeoPrompt::create(['site_id' => $site->id, 'service_id' => $svc->id, 'coverage_area_id' => $town->id, 'size_tier' => 'major', 'prompt' => 'best repair union', 'active' => true]);
    verSnap($vis, cited: true);
    verSnap(coveragePrompt($site, $svc, $town), cited: true);

    $report = app(GeoCoverage::class)->report($site);
    expect($report['summary']['prompts'])->toBe(1);   // only the visibility prompt is in the metric
});

it('the gap bridge ignores coverage-check gaps', function () {
    $site = Site::factory()->create(['brand_name' => 'SPG']);
    $svc = Service::factory()->create(['site_id' => $site->id, 'name' => 'Repair']);
    $town = verTown($site, 'Union');

    // An absent coverage prompt would look like a gap, but it's not a content gap.
    verSnap(coveragePrompt($site, $svc, $town), cited: false);

    expect((new GeoGapBridge)->bridge($site))->toMatchArray(['created' => 0, 'gaps' => 0]);
});

it('reports coverage verdicts: confirmed / unaware / negative / unknown', function () {
    $site = Site::factory()->create(['brand_name' => 'SPG']);
    $svc = Service::factory()->create(['site_id' => $site->id, 'name' => 'Repair']);

    verSnap(coveragePrompt($site, $svc, verTown($site, 'ConfirmTown')), cited: true, sentiment: 'positive');   // confirmed
    verSnap(coveragePrompt($site, $svc, verTown($site, 'UnawareTown')), cited: false);                          // unaware
    verSnap(coveragePrompt($site, $svc, verTown($site, 'NegativeTown')), cited: true, sentiment: 'negative');   // negative
    coveragePrompt($site, $svc, verTown($site, 'UnknownTown'));                                                  // no snapshot → unknown

    $report = app(GeoCoverageVerification::class)->report($site);

    expect($report['summary'])->toMatchArray(['confirmed' => 1, 'unaware' => 1, 'negative' => 1, 'unknown' => 1])
        ->and($report['total'])->toBe(4)
        // Worst-first ordering: unaware leads.
        ->and($report['rows'][0]['verdict'])->toBe('unaware');
});

it('scopes the coverage verdicts to a brick-and-mortar shop', function () {
    $site = Site::factory()->create(['brand_name' => 'SPG']);
    $svc = Service::factory()->create(['site_id' => $site->id, 'name' => 'Repair']);
    $shopA = 'loc-a';

    verSnap(coveragePrompt($site, $svc, verTown($site, 'AtownA', 'major', [$shopA])), cited: true);
    verSnap(coveragePrompt($site, $svc, verTown($site, 'BtownB', 'major', ['loc-b'])), cited: true);

    $scoped = app(GeoCoverageVerification::class)->report($site, $shopA);
    expect($scoped['total'])->toBe(1)
        ->and($scoped['rows'][0]['town'])->toBe('AtownA');
});

it('the seed-geo-coverage-prompts command runs for a site', function () {
    $site = Site::factory()->create(['brand_name' => 'Sump Pump Gurus']);
    Service::factory()->create(['site_id' => $site->id, 'name' => 'Repair']);
    verTown($site, 'Union');

    $this->artisan('sandhog:seed-geo-coverage-prompts', ['site' => $site->id])
        ->expectsOutputToContain('coverage-check prompt')
        ->assertSuccessful();
});
