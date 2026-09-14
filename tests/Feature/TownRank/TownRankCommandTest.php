<?php

use App\Enums\ContentStatus;
use App\Enums\PageType;
use App\Models\Content;
use App\Models\CoverageArea;
use App\Models\GeoGridPoint;
use App\Models\GeoGridScan;
use App\Models\Keyword;
use App\Models\Location;
use App\Models\Site;
use App\Models\TownRankPoint;
use App\Models\TownRankScan;
use App\TownRank\TownRankReport;
use Illuminate\Support\Facades\Http;

beforeEach(function () {
    config()->set('launchpad.town_rank.poll_interval_seconds', 0);
    config()->set('launchpad.town_rank.poll_max_attempts', 2);
});

function cmdSite(): array
{
    $site = Site::factory()->create(['brand_name' => 'Sump Pump Gurus', 'domain_url' => 'https://spg.com']);
    $loc = Location::factory()->create(['site_id' => $site->id, 'lat' => 40.85, 'lng' => -74.83]);
    $hack = CoverageArea::factory()->create(['site_id' => $site->id, 'name' => 'Hackettstown', 'state' => 'NJ', 'population' => 10000, 'lat' => 40.854, 'lng' => -74.829, 'source_location_ids' => [$loc->id], 'geo_id' => '3404128590']);
    $mans = CoverageArea::factory()->create(['site_id' => $site->id, 'name' => 'Mansfield', 'state' => 'NJ', 'population' => 7000, 'lat' => 40.80, 'lng' => -74.85, 'source_location_ids' => [$loc->id], 'geo_id' => '3404143050']);
    Content::factory()->page()->published()->create(['site_id' => $site->id, 'page_type' => PageType::Location, 'status' => ContentStatus::Published, 'geo_id' => '3404128590', 'slug' => 'hackettstown-nj']);
    $keyword = Keyword::factory()->create(['site_id' => $site->id, 'query' => 'sump pump repair', 'is_grid_keyword' => true]);

    return [$site, $keyword, $loc, $hack, $mans];
}

function cmdFakeQueue(int $count, array $items): void
{
    $ids = collect(range(0, max(0, $count - 1)))->map(fn ($i): string => "ctask-{$i}");
    Http::fake([
        '*/serp/google/organic/task_post' => Http::response(['status_code' => 20000, 'tasks' => $ids->map(fn ($id): array => ['id' => $id, 'status_code' => 20000])->all()]),
        '*/serp/google/organic/tasks_ready' => Http::response(['status_code' => 20000, 'tasks' => [['id' => 'r', 'status_code' => 20000, 'result' => $ids->map(fn ($id): array => ['id' => $id])->all()]]]),
        '*/serp/google/organic/task_get/advanced/*' => Http::response(['status_code' => 20000, 'tasks' => [['id' => 'g', 'status_code' => 20000, 'result' => [['items' => $items]]]]]),
    ]);
}

it('reports the stored picture by default — no scan yet says so, nothing is sent', function () {
    Http::fake();
    [$site] = cmdSite();

    $this->artisan('launchpad:town-rank', ['site' => 'Sump Pump Gurus'])
        ->expectsOutputToContain('Keyword: sump pump repair')
        ->expectsOutputToContain('no scan yet')
        ->expectsOutputToContain('Hackettstown, NJ')
        ->assertExitCode(0);
    Http::assertNothingSent();
});

it('plans a scan with --scan --dry-run: towns × modes → requests + cost, no API calls', function () {
    Http::fake();
    [$site] = cmdSite();

    $this->artisan('launchpad:town-rank', ['site' => $site->id, '--scan' => true, '--dry-run' => true])
        ->expectsOutputToContain('2 (1 with a published page)')
        ->expectsOutputToContain('4 (1 per town × mode × keyword)')   // 2 towns × 2 modes × 1 keyword
        ->expectsOutputToContain('Dry run')
        ->assertExitCode(0);
    Http::assertNothingSent();
});

it('posts, polls in, and prints the town table with rank, page, and ranking URL', function () {
    cmdFakeQueue(2, [
        ['type' => 'organic', 'rank_absolute' => 1, 'url' => 'https://rival.com/x', 'domain' => 'rival.com'],
        ['type' => 'organic', 'rank_absolute' => 5, 'url' => 'https://spg.com/hackettstown-nj/', 'domain' => 'spg.com'],
    ]);
    [$site, $keyword] = cmdSite();

    // --yes: no prompt (the hosted Commands panel can't answer one). After a scan only the summary prints;
    // the table needs --town.
    $this->artisan('launchpad:town-rank', ['site' => $site->id, '--scan' => true, '--mode' => 'local', '--yes' => true])
        ->expectsOutputToContain('2/2 towns collected · complete')
        ->expectsOutputToContain('page-1 2')
        ->expectsOutputToContain('pass --town=<name>')
        ->doesntExpectOutputToContain('#5')
        ->assertExitCode(0);

    $this->artisan('launchpad:town-rank', ['site' => $site->id, '--mode' => 'local', '--town' => 'hackett'])
        ->expectsOutputToContain('#5')   // one substring per printed line: the URL sits on this same row, so it is asserted below
        ->assertExitCode(0);

    $scan = TownRankScan::query()->withoutGlobalScopes()->where('keyword_id', $keyword->id)->where('mode', 'local')->first();
    expect($scan?->status)->toBe('complete')
        ->and($scan?->found_count)->toBe(2)
        ->and($scan?->points()->where('label', 'Hackettstown')->value('ranking_url'))->toBe('https://spg.com/hackettstown-nj/');
});

it('refuses a scan over the hard ceiling and cancels cleanly without confirmation', function () {
    Http::fake();
    [$site] = cmdSite();
    config()->set('launchpad.town_rank.request_ceiling', 3);

    $this->artisan('launchpad:town-rank', ['site' => $site->id, '--scan' => true])
        ->expectsOutputToContain('ABORTED')
        ->assertExitCode(1);
    Http::assertNothingSent();

    config()->set('launchpad.town_rank.request_ceiling', 100);
    $this->artisan('launchpad:town-rank', ['site' => $site->id, '--scan' => true, '--mode' => 'town'])
        ->expectsConfirmation('Post 2 DataForSEO request(s) (~$0.00)?', 'no')
        ->expectsOutputToContain('Cancelled')
        ->assertExitCode(0);
    Http::assertNothingSent();
});

it('prints only the summary for a large footprint unless --town slices it', function () {
    Http::fake();
    [$site, $keyword, $loc] = cmdSite();
    config()->set('launchpad.town_rank.request_ceiling', 10000);
    for ($i = 0; $i < 85; $i++) {
        CoverageArea::factory()->create(['site_id' => $site->id, 'name' => "Town {$i}", 'population' => 100 + $i, 'lat' => 40.0 + $i / 1000, 'lng' => -74.0, 'source_location_ids' => [$loc->id]]);
    }

    $this->artisan('launchpad:town-rank', ['site' => $site->id])
        ->expectsOutputToContain('87 covered towns — pass --town=<name>')
        ->doesntExpectOutputToContain('Town 42')
        ->assertExitCode(0);
    $this->artisan('launchpad:town-rank', ['site' => $site->id, '--town' => 'Town 42'])
        ->expectsOutputToContain('Town 42')
        ->assertExitCode(0);
});

it('joins the latest scan per mode onto every covered town with the map-pack rank beside it, and filters by --town', function () {
    Http::fake();
    [$site, $keyword, $loc, $hack, $mans] = cmdSite();

    $local = TownRankScan::create(['site_id' => $site->id, 'keyword_id' => $keyword->id, 'mode' => 'local', 'status' => 'complete', 'points_count' => 2, 'found_count' => 1, 'scanned_at' => now()]);
    TownRankPoint::create(['site_id' => $site->id, 'scan_id' => $local->id, 'coverage_area_id' => $hack->id, 'label' => 'Hackettstown', 'state' => 'NJ', 'lat' => 40.854, 'lng' => -74.829, 'query' => 'sump pump repair', 'rank' => 2, 'ranking_url' => 'https://spg.com/hackettstown-nj/', 'collected_at' => now()]);
    TownRankPoint::create(['site_id' => $site->id, 'scan_id' => $local->id, 'coverage_area_id' => $mans->id, 'label' => 'Mansfield', 'state' => 'NJ', 'lat' => 40.80, 'lng' => -74.85, 'query' => 'sump pump repair', 'rank' => null, 'collected_at' => now()]);
    $gg = GeoGridScan::create(['site_id' => $site->id, 'location_id' => $loc->id, 'keyword_id' => $keyword->id, 'provider' => 'dataforseo', 'mode' => 'coverage', 'grid_size' => 2, 'spacing_miles' => 0, 'center_lat' => 40.85, 'center_lng' => -74.83, 'zoom' => 13, 'depth_cap' => 20, 'status' => 'complete', 'scanned_at' => now()]);
    GeoGridPoint::create(['site_id' => $site->id, 'scan_id' => $gg->id, 'row' => 0, 'col' => 0, 'lat' => 40.854, 'lng' => -74.829, 'rank' => 1, 'coverage_area_id' => $hack->id, 'label' => 'Hackettstown']);

    $report = app(TownRankReport::class)->forKeyword($site, $keyword);
    expect($report['summary']['local'])->toMatchArray(['top3' => 1, 'not_found' => 1, 'pending' => 0])
        ->and($report['scans']['town_query'])->toBeNull()
        ->and($report['rows'][0]['label'])->toBe('Hackettstown')
        ->and($report['rows'][0]['local_rank'])->toBe(2)
        ->and($report['rows'][0]['map_rank'])->toBe(1)
        ->and($report['rows'][0]['town_state'])->toBe('unscanned')
        ->and($report['rows'][1]['local_state'])->toBe('not_found')
        ->and($report['rows'][1]['map_rank'])->toBeNull();

    $this->artisan('launchpad:town-rank', ['site' => $site->id, '--town' => 'hackett'])
        ->expectsOutputToContain('top-3 1')
        ->expectsOutputToContain('Hackettstown, NJ')
        ->doesntExpectOutputToContain('Mansfield')
        ->assertExitCode(0);
});

it('--keyword prefers an exact query match over substring neighbours, and falls back to substring only when nothing is exact', function () {
    Http::fake();
    [$site] = cmdSite();   // tracks "sump pump repair" (grid)
    Keyword::factory()->create(['site_id' => $site->id, 'query' => 'sump pump service', 'is_grid_keyword' => false]);
    Keyword::factory()->create(['site_id' => $site->id, 'query' => 'commercial sump pump service', 'is_grid_keyword' => false]);

    // Exact: one keyword → 2 towns × 2 modes = 4 requests, and the plan names only it.
    $this->artisan('launchpad:town-rank', ['site' => $site->id, '--keyword' => 'Sump Pump Service', '--scan' => true, '--dry-run' => true])
        ->expectsOutputToContain('4 (1 per town × mode × keyword)')
        ->doesntExpectOutputToContain('commercial sump pump service')
        ->assertExitCode(0);

    // Substring fallback: "pump service" matches both service keywords → 8 requests.
    $this->artisan('launchpad:town-rank', ['site' => $site->id, '--keyword' => 'pump service', '--scan' => true, '--dry-run' => true])
        ->expectsOutputToContain('8 (1 per town × mode × keyword)')
        ->assertExitCode(0);
    Http::assertNothingSent();
});
