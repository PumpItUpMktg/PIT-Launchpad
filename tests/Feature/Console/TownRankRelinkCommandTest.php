<?php

use App\Models\CoverageArea;
use App\Models\GeoGridPoint;
use App\Models\GeoGridScan;
use App\Models\Keyword;
use App\Models\Location;
use App\Models\Site;
use App\Models\TownRankPoint;
use App\Models\TownRankScan;
use App\TownRank\TownRankPoints;
use Illuminate\Support\Facades\Artisan;

/** Two towns ranked, then a coverage rebuild: fresh row ids, the points left pointing at the dead ones. */
function relinkFixture(): array
{
    $site = Site::factory()->create(['brand_name' => 'Sump Pump Gurus', 'domain_url' => 'https://spg.com']);
    $loc = Location::factory()->create(['site_id' => $site->id, 'lat' => 40.85, 'lng' => -74.83, 'home_county_geoid' => '34041', 'county_geoids' => []]);
    $mk = fn (string $name, string $geoId, float $lat, int $pop): CoverageArea => CoverageArea::factory()->create([
        'site_id' => $site->id, 'name' => $name, 'state' => 'NJ', 'geo_id' => $geoId, 'population' => $pop,
        'lat' => $lat, 'lng' => -74.83, 'source_location_ids' => [$loc->id],
    ]);
    $oldHack = $mk('Hackettstown', '3404128590', 40.85, 10000);
    $oldGone = $mk('Longgone', '3404100000', 40.70, 5000);

    $kw = Keyword::factory()->create(['site_id' => $site->id, 'query' => 'sump pump service']);
    $scan = TownRankScan::create(['site_id' => $site->id, 'keyword_id' => $kw->id, 'mode' => 'town_query', 'status' => 'complete', 'points_count' => 2, 'found_count' => 2, 'scanned_at' => now()->subDay()]);
    foreach ([[$oldHack, 3], [$oldGone, 5]] as [$area, $rank]) {
        TownRankPoint::create(['site_id' => $site->id, 'scan_id' => $scan->id, 'coverage_area_id' => $area->id, 'geo_id' => null, 'label' => $area->name, 'state' => 'NJ', 'lat' => $area->lat, 'lng' => -74.83, 'query' => 'q', 'rank' => $rank, 'collected_at' => now()->subDay()]);
    }

    // The same towns also carry a map-pack (coverage) scan — the Service Areas GBP map's source.
    $gg = GeoGridScan::create(['site_id' => $site->id, 'location_id' => $loc->id, 'keyword_id' => $kw->id, 'provider' => 'dataforseo', 'mode' => 'coverage', 'grid_size' => 1, 'spacing_miles' => 0, 'center_lat' => 40.85, 'center_lng' => -74.83, 'zoom' => 13, 'depth_cap' => 20, 'status' => 'complete', 'scanned_at' => now()->subDay()]);
    GeoGridPoint::create(['site_id' => $site->id, 'scan_id' => $gg->id, 'row' => 0, 'col' => 0, 'lat' => 40.85, 'lng' => -74.83, 'rank' => 1, 'coverage_area_id' => $oldHack->id, 'label' => 'Hackettstown', 'collected_at' => now()->subDay()]);

    // Rebuild: Hackettstown comes back with a new id, Longgone is no longer covered.
    CoverageArea::withoutGlobalScopes()->whereIn('id', [$oldHack->id, $oldGone->id])->delete();
    $newHack = $mk('Hackettstown', '3404128590', 40.85, 10000);
    app(TownRankPoints::class)->forget();

    return compact('site', 'scan', 'newHack');
}

it('reports the stale links and the ranked towns they hide, and writes nothing by default', function () {
    $f = relinkFixture();

    expect(Artisan::call('launchpad:town-rank-relink', ['site' => 'Sump Pump Gurus']))->toBe(0);
    $out = Artisan::output();

    expect($out)->toContain('sump pump service')->toContain('town search')
        ->toContain('GBP map pack')
        ->toContain('3 carry a stale town link, 2 of those re-link')
        ->toContain('2 of them RANKED')
        ->toContain('1 measure a town no longer covered')
        ->toContain('Read-only');
    expect(TownRankPoint::whereNotNull('geo_id')->count())->toBe(0);   // nothing written
});

it('--execute stamps the GEOID and the current town id, and leaves a point whose town is gone alone', function () {
    $f = relinkFixture();

    expect(Artisan::call('launchpad:town-rank-relink', ['site' => 'Sump Pump Gurus', '--execute' => true]))->toBe(0);
    expect(Artisan::output())->toContain('Wrote 2 point(s)');

    $byLabel = $f['scan']->points()->get()->keyBy('label');
    expect($byLabel['Hackettstown']->coverage_area_id)->toBe((string) $f['newHack']->id)
        ->and($byLabel['Hackettstown']->geo_id)->toBe('3404128590')
        ->and($byLabel['Hackettstown']->rank)->toBe(3)                 // the rank itself is untouched
        ->and($byLabel['Longgone']->geo_id)->toBeNull()                // no current town to link to
        ->and($byLabel['Longgone']->rank)->toBe(5);

    // The map-pack point is re-linked too, so the Service Areas GBP map finds its town again.
    $gbp = GeoGridPoint::withoutGlobalScopes()->whereNotNull('coverage_area_id')->where('label', 'Hackettstown')->sole();
    expect($gbp->coverage_area_id)->toBe((string) $f['newHack']->id)
        ->and($gbp->geo_id)->toBe('3404128590');

    // A second run has nothing left to do.
    Artisan::call('launchpad:town-rank-relink', ['site' => 'Sump Pump Gurus']);
    expect(Artisan::output())->toContain('every point already links to its current town by GEOID');
});
