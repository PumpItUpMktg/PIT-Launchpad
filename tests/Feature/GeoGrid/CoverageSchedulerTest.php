<?php

use App\Enums\ScanCadence;
use App\GeoGrid\CoveragePlanEstimator;
use App\Jobs\RunCoverageScan;
use App\Jobs\SeedSiteCoverage;
use App\Locations\CountyCoverage;
use App\Locations\CoverageWriter;
use App\Models\CoverageArea;
use App\Models\CoverageScanPlan;
use App\Models\Keyword;
use App\Models\Location;
use App\Models\Silo;
use App\Models\Site;
use App\Operator\Controls\CoveragePlanControl;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;

function gbpLoc(Site $site, array $extra = []): Location
{
    return Location::factory()->create(array_merge([
        'site_id' => $site->id, 'name' => 'Montclair',
        'gbp_url' => 'https://maps.google/?cid=1', 'place_id' => 'p', 'lat' => 40.81, 'lng' => -74.22,
    ], $extra));
}

function servedTowns(Site $site, Location $loc, int $n): void
{
    for ($i = 0; $i < $n; $i++) {
        CoverageArea::factory()->create(['site_id' => $site->id, 'name' => "Town {$i}", 'population' => 10000, 'lat' => 40.8 + $i * 0.01, 'lng' => -74.2, 'source_location_ids' => [$loc->id]]);
    }
}

it('advances cadence and pauses on Off', function () {
    $from = Carbon::parse('2026-08-01 09:00:00');
    expect(ScanCadence::Monthly->advance($from)->toDateString())->toBe('2026-09-01')
        ->and(ScanCadence::Weekly->advance($from)->toDateString())->toBe('2026-08-08')
        ->and(ScanCadence::Off->advance($from))->toBeNull();
});

it('reconciles next_run_at on save: enabled → due now, off/disabled → dormant', function () {
    $site = Site::factory()->create();
    $loc = gbpLoc($site);

    $plan = CoverageScanPlan::create(['site_id' => $site->id, 'location_id' => $loc->id, 'cadence' => ScanCadence::Monthly, 'enabled' => true, 'keyword_ids' => []]);
    expect($plan->next_run_at)->not->toBeNull()->and($plan->isDue())->toBeTrue();

    // A future next_run (set by the scheduler after a run) is preserved.
    $plan->forceFill(['next_run_at' => now()->addMonth()])->save();
    expect($plan->fresh()->next_run_at->isFuture())->toBeTrue();

    // Disabling clears it.
    $plan->forceFill(['enabled' => false])->save();
    expect($plan->fresh()->next_run_at)->toBeNull();

    // Off cadence stays dormant even when enabled.
    $plan->forceFill(['enabled' => true, 'cadence' => ScanCadence::Off])->save();
    expect($plan->fresh()->next_run_at)->toBeNull();
});

it('estimates a run as towns × keywords × cost', function () {
    $site = Site::factory()->create();
    $loc = gbpLoc($site);
    servedTowns($site, $loc, 5);

    $e = app(CoveragePlanEstimator::class)->estimate($loc->fresh(), 3);   // 5 towns × 3 kw = 15 req
    expect($e['towns'])->toBe(5)->and($e['keywords'])->toBe(3)
        ->and($e['requests'])->toBe(15)
        ->and($e['cost'])->toBe(round(15 * (float) config('launchpad.geo_grid.cost_per_request'), 2));
});

it('offers each silo\'s buyer-intent keywords (no pre-flagging), excluding informational longtails', function () {
    $site = Site::factory()->create();
    $silo = Silo::factory()->create(['site_id' => $site->id, 'name' => 'Sump Pumps']);
    // Buyer-intent keywords are offered WITHOUT is_grid_keyword, opportunity-ranked.
    Keyword::factory()->create(['site_id' => $site->id, 'silo_id' => $silo->id, 'query' => 'sump pump installation', 'intent' => 'transactional', 'opportunity_score' => 9, 'is_grid_keyword' => false]);
    Keyword::factory()->create(['site_id' => $site->id, 'silo_id' => $silo->id, 'query' => 'best sump pump', 'intent' => 'commercial', 'opportunity_score' => 4, 'is_grid_keyword' => false]);
    // Informational longtail → excluded unless flagged.
    Keyword::factory()->create(['site_id' => $site->id, 'silo_id' => $silo->id, 'query' => 'why is my basement wet', 'intent' => 'informational', 'is_grid_keyword' => false]);
    Keyword::factory()->create(['site_id' => $site->id, 'silo_id' => null, 'query' => 'loose keyword', 'intent' => 'transactional', 'is_grid_keyword' => false]);

    $opts = app(CoveragePlanControl::class)->keywordOptions($site->id);

    expect($opts)->toHaveKey('Sump Pumps')->toHaveKey('Ungrouped')
        // Highest opportunity first; the informational one is dropped.
        ->and(collect($opts['Sump Pumps'])->values()->all())->toBe(['sump pump installation', 'best sump pump'])
        ->and(collect($opts['Ungrouped'])->values()->all())->toBe(['loose keyword']);
});

it('still offers a flagged informational keyword, and caps per silo', function () {
    config()->set('launchpad.geo_grid.dropdown_per_silo', 2);
    $site = Site::factory()->create();
    $silo = Silo::factory()->create(['site_id' => $site->id, 'name' => 'Sump Pumps']);
    // Flagged informational stays (explicit operator choice), and sorts first (flagged-first).
    Keyword::factory()->create(['site_id' => $site->id, 'silo_id' => $silo->id, 'query' => 'flagged info kw', 'intent' => 'informational', 'is_grid_keyword' => true]);
    Keyword::factory()->create(['site_id' => $site->id, 'silo_id' => $silo->id, 'query' => 'kw a', 'intent' => 'transactional', 'opportunity_score' => 8, 'is_grid_keyword' => false]);
    Keyword::factory()->create(['site_id' => $site->id, 'silo_id' => $silo->id, 'query' => 'kw b', 'intent' => 'transactional', 'opportunity_score' => 1, 'is_grid_keyword' => false]);

    $opts = app(CoveragePlanControl::class)->keywordOptions($site->id);

    // Cap = 2: flagged first, then the top-opportunity buyer keyword; 'kw b' drops off.
    expect(collect($opts['Sump Pumps'])->values()->all())->toBe(['flagged info kw', 'kw a']);
});

it('flags the plan\'s keywords as grid keywords on save (the plan is the source of truth)', function () {
    $site = Site::factory()->create();
    $loc = gbpLoc($site);
    $kw = Keyword::factory()->create(['site_id' => $site->id, 'query' => 'sump pump installation', 'intent' => 'transactional', 'is_grid_keyword' => false]);

    app(CoveragePlanControl::class)->save($loc, [$kw->id], ScanCadence::Monthly, true);

    expect($kw->refresh()->is_grid_keyword)->toBeTrue();
});

it('the SeedSiteCoverage job no-ops for an unknown site', function () {
    (new SeedSiteCoverage((string) Str::ulid()))
        ->handle(app(CountyCoverage::class), app(CoverageWriter::class));

    expect(CoverageArea::count())->toBe(0);   // returns before touching Census/writer
});

it('run-now dispatches one job per keyword and stamps last_run_at', function () {
    Queue::fake();
    $site = Site::factory()->create();
    $loc = gbpLoc($site);
    $k1 = Keyword::factory()->create(['site_id' => $site->id, 'is_grid_keyword' => true]);
    $k2 = Keyword::factory()->create(['site_id' => $site->id, 'is_grid_keyword' => true]);
    $plan = CoverageScanPlan::create(['site_id' => $site->id, 'location_id' => $loc->id, 'cadence' => ScanCadence::Monthly, 'enabled' => true, 'keyword_ids' => [$k1->id, $k2->id]]);

    $n = app(CoveragePlanControl::class)->runNow($plan);

    expect($n)->toBe(2);
    Queue::assertPushed(RunCoverageScan::class, 2);
    expect($plan->fresh()->last_run_at)->not->toBeNull();
});
