<?php

use App\Enums\ContentStatus;
use App\Enums\PageType;
use App\Models\Content;
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
        ->and($data['scans']['local']['collected'])->toBe(0)
        ->and($data['scans']['town_query']['collected'])->toBe(5)
        ->and($data['scans']['local']['previous_scanned_at'])->toBe('2026-09-01 10:00:00')
        ->and($rows[$areas['A']]['local_state'])->toBe('pending')
        ->and($rows[$areas['A']]['local_change'])->toBeNull()
        ->and($rows[$areas['A']]['local_prev_rank'])->toBe(6)
        ->and($data['summary']['local']['pending'])->toBe(1);

    expect(TownRankReport::changeOf(null, null))->toBe('same')
        ->and(TownRankReport::changeOf(3, 3))->toBe('same');
});

it('--since picks the newest finished scan at least that many days before the latest as the baseline, and the command prints the movers digest', function () {
    $site = Site::factory()->create(['brand_name' => 'Sump Pump Gurus', 'domain_url' => 'https://spg.com']);
    $loc = Location::factory()->create(['site_id' => $site->id, 'lat' => 40.0, 'lng' => -74.0]);
    $areas = [];
    foreach (['Hackettstown', 'Mansfield', 'Budd Lake'] as $i => $name) {
        $areas[$name] = CoverageArea::factory()->create(['site_id' => $site->id, 'name' => $name, 'state' => 'NJ', 'population' => 1000 - $i, 'lat' => 40.0 + $i / 100, 'lng' => -74.0, 'source_location_ids' => [$loc->id]])->id;
    }
    $kw = Keyword::factory()->create(['site_id' => $site->id, 'query' => 'sump pump repair', 'is_grid_keyword' => true]);

    // Two weeks ago, one week ago, today.
    reportScan($site, $kw, 'town_query', 'complete', '2026-10-15 10:00:00', [$areas['Hackettstown'] => 14, $areas['Mansfield'] => null, $areas['Budd Lake'] => 2]);
    reportScan($site, $kw, 'town_query', 'complete', '2026-10-22 10:00:00', [$areas['Hackettstown'] => 9, $areas['Mansfield'] => 30, $areas['Budd Lake'] => 2]);
    reportScan($site, $kw, 'town_query', 'complete', '2026-10-29 10:00:00', [$areas['Hackettstown'] => 3, $areas['Mansfield'] => 18, $areas['Budd Lake'] => null]);

    $report = app(TownRankReport::class);
    // Default: against the scan just before the latest.
    expect($report->forKeyword($site, $kw)['scans']['town_query']['previous_scanned_at'])->toBe('2026-10-22 10:00:00');
    // --since=14: against the newest finished scan at least 14 days older.
    $data = $report->forKeyword($site, $kw, 14);
    $rows = collect($data['rows'])->keyBy('coverage_area_id');
    expect($data['scans']['town_query']['previous_scanned_at'])->toBe('2026-10-15 10:00:00')
        ->and($rows[$areas['Hackettstown']]['town_prev_rank'])->toBe(14)->and($rows[$areas['Hackettstown']]['town_change'])->toBe('up')
        ->and($rows[$areas['Mansfield']]['town_change'])->toBe('new')
        ->and($rows[$areas['Budd Lake']]['town_change'])->toBe('lost');
    // Further back than any scan: no baseline.
    expect($report->forKeyword($site, $kw, 60)['scans']['town_query']['previous_scanned_at'])->toBeNull();

    $this->artisan('launchpad:town-rank', ['site' => $site->id, '--mode' => 'town', '--since' => 14])
        ->expectsOutputToContain('2026-10-15 10:00:00 → 2026-10-29 10:00:00')
        ->expectsOutputToContain('▲ up 1 · ▼ down 0 · new 1 · lost 1 · unchanged 0')
        ->expectsOutputToContain('Biggest gains:')
        ->expectsOutputToContain('Mansfield, NJ')
        ->expectsOutputToContain('Biggest losses:')
        ->expectsOutputToContain('Budd Lake, NJ')
        ->assertExitCode(0);
});

it('sets aside a town whose page went live after the baseline, and never counts an unreadable point as lost', function () {
    $site = Site::factory()->create(['brand_name' => 'Sump Pump Gurus', 'domain_url' => 'https://spg.com']);
    $loc = Location::factory()->create(['site_id' => $site->id, 'lat' => 40.0, 'lng' => -74.0]);
    $areas = [];
    foreach ([['Hackettstown', '3404128590'], ['Newpage', '3404199999'], ['Unread', '3404188888']] as $i => [$name, $geo]) {
        $areas[$name] = CoverageArea::factory()->create(['site_id' => $site->id, 'name' => $name, 'state' => 'NJ', 'population' => 1000 - $i, 'lat' => 40.0 + $i / 100, 'lng' => -74.0, 'source_location_ids' => [$loc->id], 'geo_id' => $geo])->id;
    }
    // Newpage's town page went live between the baseline and the latest scan.
    Content::factory()->page()->published()->create(['site_id' => $site->id, 'page_type' => PageType::Location, 'status' => ContentStatus::Published, 'geo_id' => '3404199999', 'slug' => 'newpage-nj', 'published_at' => '2026-10-20 09:00:00']);
    $kw = Keyword::factory()->create(['site_id' => $site->id, 'query' => 'sump pump repair', 'is_grid_keyword' => true]);

    reportScan($site, $kw, 'town_query', 'complete', '2026-10-15 10:00:00', [$areas['Hackettstown'] => 4, $areas['Newpage'] => null, $areas['Unread'] => 1]);
    $latest = reportScan($site, $kw, 'town_query', 'complete', '2026-10-29 10:00:00', [$areas['Hackettstown'] => 4, $areas['Newpage'] => 2, $areas['Unread'] => null]);
    TownRankPoint::where('scan_id', $latest->id)->where('coverage_area_id', $areas['Unread'])->update(['read_error' => 'no answer after 3 reads']);

    $data = app(TownRankReport::class)->forKeyword($site, $kw, 14);
    $rows = collect($data['rows'])->keyBy('coverage_area_id');
    expect($rows[$areas['Unread']]['town_state'])->toBe('unreadable')
        ->and($rows[$areas['Unread']]['town_change'])->toBeNull()     // no data ≠ lost #1
        ->and($rows[$areas['Newpage']]['page_published_at'])->toBe('2026-10-20 09:00:00')
        ->and($data['summary']['town_query']['lost'])->toBe(0);

    $this->artisan('launchpad:town-rank', ['site' => $site->id, '--mode' => 'town', '--since' => 14])
        ->expectsOutputToContain('unreadable 1')
        ->expectsOutputToContain('▲ up 0 · ▼ down 0 · new 0 · lost 0 · unchanged 1')
        ->expectsOutputToContain('1 town(s) could not be read this scan')
        ->expectsOutputToContain('1 town page(s) published after the baseline — set aside, not compared: Newpage, NJ #2')
        ->doesntExpectOutputToContain('Biggest losses:')
        ->assertExitCode(0);
});

it('a "not found" with no results stored is a phantom read — no data, never a loss — while a real not-found keeps the results that ranked', function () {
    $site = Site::factory()->create(['brand_name' => 'Sump Pump Gurus', 'domain_url' => 'https://spg.com']);
    $loc = Location::factory()->create(['site_id' => $site->id, 'lat' => 40.0, 'lng' => -74.0]);
    $phantom = CoverageArea::factory()->create(['site_id' => $site->id, 'name' => 'Phantom', 'state' => 'NJ', 'population' => 900, 'lat' => 40.0, 'lng' => -74.0, 'source_location_ids' => [$loc->id]])->id;
    $real = CoverageArea::factory()->create(['site_id' => $site->id, 'name' => 'Really Gone', 'state' => 'NJ', 'population' => 800, 'lat' => 40.01, 'lng' => -74.0, 'source_location_ids' => [$loc->id]])->id;
    $kw = Keyword::factory()->create(['site_id' => $site->id, 'query' => 'sump pump repair', 'is_grid_keyword' => true]);

    reportScan($site, $kw, 'town_query', 'complete', '2026-10-15 10:00:00', [$phantom => 1, $real => 1]);
    $latest = reportScan($site, $kw, 'town_query', 'complete', '2026-10-29 10:00:00', [$phantom => null, $real => null]);
    TownRankPoint::where('scan_id', $latest->id)->where('coverage_area_id', $phantom)->update(['provider_task_id' => 't-1', 'top_results' => []]);
    TownRankPoint::where('scan_id', $latest->id)->where('coverage_area_id', $real)->update(['provider_task_id' => 't-2', 'top_results' => [['position' => 1, 'url' => 'https://rival.com/x', 'domain' => 'rival.com']]]);

    $data = app(TownRankReport::class)->forKeyword($site, $kw, 14);
    $rows = collect($data['rows'])->keyBy('coverage_area_id');
    expect($rows[$phantom]['town_state'])->toBe('unreadable')
        ->and($rows[$phantom]['town_change'])->toBeNull()
        ->and($rows[$real]['town_state'])->toBe('not_found')
        ->and($rows[$real]['town_change'])->toBe('lost')
        ->and($data['summary']['town_query'])->toMatchArray(['unreadable' => 1, 'not_found' => 1, 'lost' => 1]);

    $this->artisan('launchpad:town-rank', ['site' => $site->id, '--mode' => 'town', '--since' => 14])
        ->expectsOutputToContain('unreadable 1')
        ->expectsOutputToContain('▲ up 0 · ▼ down 0 · new 0 · lost 1 · unchanged 0')
        ->expectsOutputToContain('Really Gone, NJ                    #1 → not found')
        ->doesntExpectOutputToContain('Phantom, NJ                        #1')   // never in the losses list
        ->assertExitCode(0);

    // A sliced table shows what the scan saw at the top of the page — the evidence behind the "not found".
    expect($rows[$real]['town_top'])->toBe(['1 rival.com'])->and($rows[$phantom]['town_top'])->toBe([]);
    $this->artisan('launchpad:town-rank', ['site' => $site->id, '--mode' => 'town', '--town' => 'Really'])
        ->expectsOutputToContain('Top of page (scan)')
        ->expectsOutputToContain('1 rival.com')
        ->assertExitCode(0);
});
