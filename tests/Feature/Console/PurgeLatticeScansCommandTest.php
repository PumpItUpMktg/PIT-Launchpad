<?php

use App\Models\GeoGridPoint;
use App\Models\GeoGridScan;
use App\Models\Keyword;
use App\Models\Location;
use App\Models\Site;
use Illuminate\Support\Facades\Artisan;

/** A site with one retired lattice scan (4 points) and one live coverage scan (2 towns). */
function latticeFixture(string $brand = 'Sump Pump Gurus'): array
{
    $site = Site::factory()->create(['brand_name' => $brand]);
    $loc = Location::factory()->create(['site_id' => $site->id, 'lat' => 40.85, 'lng' => -74.83]);
    $kw = Keyword::factory()->create(['site_id' => $site->id, 'query' => 'sump pump service']);
    $mk = function (string $mode, int $points) use ($site, $loc, $kw): GeoGridScan {
        $scan = GeoGridScan::create(['site_id' => $site->id, 'location_id' => $loc->id, 'keyword_id' => $kw->id,
            'provider' => 'dataforseo', 'mode' => $mode, 'grid_size' => $points, 'spacing_miles' => $mode === 'grid' ? 1.5 : 0,
            'center_lat' => 40.85, 'center_lng' => -74.83, 'zoom' => 13, 'depth_cap' => 20, 'status' => 'complete', 'scanned_at' => now()->subDay()]);
        foreach (range(0, $points - 1) as $i) {
            GeoGridPoint::create(['site_id' => $site->id, 'scan_id' => $scan->id, 'row' => 0, 'col' => $i, 'lat' => 40.8, 'lng' => -74.8, 'rank' => 3]);
        }

        return $scan;
    };

    return ['site' => $site, 'lattice' => $mk('grid', 4), 'coverage' => $mk('coverage', 2)];
}

it('reports the lattice scans and writes nothing by default', function () {
    $f = latticeFixture();

    expect(Artisan::call('launchpad:purge-lattice-scans'))->toBe(0);

    expect(Artisan::output())->toContain('Sump Pump Gurus')
        ->toContain('1 lattice scan(s) carrying 4 point(s)')
        ->toContain('Read-only');
    expect(GeoGridScan::withoutGlobalScopes()->count())->toBe(2)
        ->and(GeoGridPoint::withoutGlobalScopes()->count())->toBe(6);
});

it('--execute deletes the lattice scans and their points, and never touches a coverage scan', function () {
    $f = latticeFixture();

    expect(Artisan::call('launchpad:purge-lattice-scans', ['--execute' => true]))->toBe(0);
    expect(Artisan::output())->toContain('Deleted 1 lattice scan(s)');

    expect(GeoGridScan::withoutGlobalScopes()->pluck('id')->all())->toBe([(string) $f['coverage']->id])
        ->and(GeoGridPoint::withoutGlobalScopes()->count())->toBe(2)                    // the lattice points cascaded
        ->and(GeoGridPoint::withoutGlobalScopes()->pluck('scan_id')->unique()->all())->toBe([(string) $f['coverage']->id]);
});

it('purges one named site only, leaving another tenant\'s lattice scans alone', function () {
    $a = latticeFixture('Sump Pump Gurus');
    $b = latticeFixture('Other Brand');

    Artisan::call('launchpad:purge-lattice-scans', ['site' => 'Sump Pump Gurus', '--execute' => true]);

    expect(GeoGridScan::withoutGlobalScopes()->where('site_id', $a['site']->id)->where('mode', 'grid')->count())->toBe(0)
        ->and(GeoGridScan::withoutGlobalScopes()->where('site_id', $b['site']->id)->where('mode', 'grid')->count())->toBe(1);
});

it('says so when there is nothing to purge', function () {
    Artisan::call('launchpad:purge-lattice-scans');

    expect(Artisan::output())->toContain('nothing to purge');
});
