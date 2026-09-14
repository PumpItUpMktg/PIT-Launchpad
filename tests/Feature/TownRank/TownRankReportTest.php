<?php

use App\Models\CoverageArea;
use App\Models\Keyword;
use App\Models\Location;
use App\Models\Site;
use App\Models\TownRankPoint;
use App\Models\TownRankScan;
use App\TownRank\TownRankReport;

function reportScan(Site $site, Keyword $kw, string $mode, string $status, string $at, array $ranks): TownRankScan
{
    $scan = TownRankScan::create(['site_id' => $site->id, 'keyword_id' => $kw->id, 'mode' => $mode, 'status' => $status, 'points_count' => count($ranks), 'found_count' => count(array_filter($ranks, fn ($r) => $r !== null)), 'scanned_at' => $at]);
    foreach ($ranks as $area => $rank) {
        TownRankPoint::create(['site_id' => $site->id, 'scan_id' => $scan->id, 'coverage_area_id' => $area, 'label' => 'T', 'lat' => 40.0, 'lng' => -74.0, 'query' => 'q', 'rank' => $rank, 'collected_at' => $status === 'pending' ? null : $at]);
    }

    return $scan;
}

it('measures movement against the previous finalized scan — up, down, new, lost, same — and skips it while the latest is pending', function () {
    $site = Site::factory()->create(['domain_url' => 'https://spg.com']);
    $loc = Location::factory()->create(['site_id' => $site->id, 'lat' => 40.0, 'lng' => -74.0]);
    $areas = [];
    foreach (['A', 'B', 'C', 'D', 'E'] as $i => $name) {
        $areas[$name] = CoverageArea::factory()->create(['site_id' => $site->id, 'name' => $name, 'population' => 1000 - $i, 'lat' => 40.0 + $i / 100, 'lng' => -74.0, 'source_location_ids' => [$loc->id]])->id;
    }
    $kw = Keyword::factory()->create(['site_id' => $site->id, 'query' => 'sump pump service']);

    // Town search: an older complete scan, then a newer complete one.
    reportScan($site, $kw, 'town_query', 'complete', '2026-09-01 10:00:00', [$areas['A'] => 8, $areas['B'] => 3, $areas['C'] => null, $areas['D'] => 12, $areas['E'] => 5]);
    reportScan($site, $kw, 'town_query', 'complete', '2026-09-08 10:00:00', [$areas['A'] => 4, $areas['B'] => 7, $areas['C'] => 9, $areas['D'] => null, $areas['E'] => 5]);
    // Searched from town: one complete scan, then a PENDING newer one — movement must not be measured against the half-collected sweep.
    reportScan($site, $kw, 'local', 'complete', '2026-09-01 10:00:00', [$areas['A'] => 6]);
    reportScan($site, $kw, 'local', 'pending', '2026-09-08 10:00:00', [$areas['A'] => null]);

    $data = app(TownRankReport::class)->forKeyword($site, $kw);
    $rows = collect($data['rows'])->keyBy('coverage_area_id');

    expect($data['scans']['town_query']['previous_scanned_at'])->toBe('2026-09-01 10:00:00')
        ->and($rows[$areas['A']]['town_change'])->toBe('up')->and($rows[$areas['A']]['town_prev_rank'])->toBe(8)
        ->and($rows[$areas['B']]['town_change'])->toBe('down')
        ->and($rows[$areas['C']]['town_change'])->toBe('new')
        ->and($rows[$areas['D']]['town_change'])->toBe('lost')
        ->and($rows[$areas['E']]['town_change'])->toBe('same')
        ->and($data['summary']['town_query'])->toMatchArray(['up' => 1, 'down' => 1, 'new' => 1, 'lost' => 1, 'same' => 1, 'top3' => 0, 'page1' => 4, 'not_found' => 1]);

    // Local: latest is pending → its previous is the complete 09-01 scan, but pending points carry no change.
    expect($data['scans']['local']['status'])->toBe('pending')
        ->and($data['scans']['local']['previous_scanned_at'])->toBe('2026-09-01 10:00:00')
        ->and($rows[$areas['A']]['local_state'])->toBe('pending')
        ->and($rows[$areas['A']]['local_change'])->toBeNull()
        ->and($rows[$areas['A']]['local_prev_rank'])->toBe(6)
        ->and($data['summary']['local']['pending'])->toBe(1);

    expect(TownRankReport::changeOf(null, null))->toBe('same')
        ->and(TownRankReport::changeOf(3, 3))->toBe('same');
});
