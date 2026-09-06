<?php

use App\Enums\SerpTaskState;
use App\Models\Keyword;
use App\Models\SerpTask;
use App\Models\Silo;
use App\Models\Site;
use Illuminate\Support\Facades\Artisan;

it('reports 40102 dead queries attributed to the tenant keyword rows behind them', function () {
    $site = Site::factory()->create(['brand_name' => 'Sump Pump Gurus']);
    $silo = Silo::factory()->create(['site_id' => $site->id, 'name' => 'Kitchen Plumbing']);
    Keyword::withoutGlobalScopes()->forceCreate([
        'site_id' => $site->id, 'silo_id' => $silo->id, 'query' => 'Kitchen Plumbing',
        'source' => 'seed', 'status' => 'scored', 'volume' => 40,
    ]);
    SerpTask::factory()->create([
        'function' => 'organic', 'task_id' => 't1',
        'cache_key' => 'dfs:organic:2840:en:'.md5('Kitchen Plumbing'),
        'query' => 'Kitchen Plumbing', 'state' => SerpTaskState::NoResults,
    ]);

    $code = Artisan::call('launchpad:report-serp-no-results');
    $out = Artisan::output();

    expect($code)->toBe(0)
        ->and($out)->toContain('1 no_results task(s) across 1 distinct query(ies).')
        ->and($out)->toContain('"Kitchen Plumbing"')
        ->and($out)->toContain('Sump Pump Gurus') // attributed to the tenant whose keyword set carries it
        ->and($out)->toContain('status scored');
});

it('reports nothing dead when there are no no_results tasks', function () {
    Artisan::call('launchpad:report-serp-no-results');

    expect(Artisan::output())->toContain('Nothing dead to report');
});
