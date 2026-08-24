<?php

use App\Enums\GeoIntent;
use App\Geo\GeoCoverage;
use App\Models\GeoPrompt;
use App\Models\GeoSnapshot;
use App\Models\Market;
use App\Models\Service;
use App\Models\Site;
use App\Support\CurrentSite;

afterEach(fn () => CurrentSite::clear());

function covPrompt(Site $site, ?Service $service, ?Market $market, GeoIntent $intent): GeoPrompt
{
    return GeoPrompt::create([
        'site_id' => $site->id, 'service_id' => $service?->id, 'market_id' => $market?->id,
        'intent' => $intent->value, 'prompt' => 'q '.$intent->value.' '.($market?->name ?? '-'), 'active' => true,
    ]);
}

function covSnap(GeoPrompt $prompt, string $engine, bool $cited, array $competitors = []): void
{
    GeoSnapshot::create([
        'site_id' => $prompt->site_id, 'geo_prompt_id' => $prompt->id, 'engine' => $engine,
        'cited' => $cited, 'competitors' => $competitors, 'checked_at' => now(),
    ]);
}

it('builds the coverage matrix + gaps + summary from tagged prompts and snapshots', function () {
    $site = Site::factory()->create(['brand_name' => 'SPG']);
    $repair = Service::factory()->create(['site_id' => $site->id, 'name' => 'Repair']);
    $install = Service::factory()->create(['site_id' => $site->id, 'name' => 'Install']);
    $prio = Market::factory()->priority()->create(['site_id' => $site->id, 'name' => 'Union']);
    $cov = Market::factory()->coverage()->create(['site_id' => $site->id, 'name' => 'Clifton']);

    // Repair × Union: cited in one of two engines → strong (cited in any).
    $rep_union = covPrompt($site, $repair, $prio, GeoIntent::Hire);
    covSnap($rep_union, 'claude', cited: true);
    covSnap($rep_union, 'perplexity', cited: false);

    // Install × Clifton: cited in NO engine, competitor named → weak + a gap.
    $ins_clifton = covPrompt($site, $install, $cov, GeoIntent::Hire);
    covSnap($ins_clifton, 'claude', cited: false, competitors: ['Rival Plumbing']);
    covSnap($ins_clifton, 'perplexity', cited: false);

    // Repair × Clifton: has a prompt but no measurement → pending.
    covPrompt($site, $repair, $cov, GeoIntent::Cost);

    // (Install × Union is untested — no prompt.)

    $r = app(GeoCoverage::class)->report($site);

    expect($r['cells'][$repair->id][$prio->id]['state'])->toBe('strong')
        ->and($r['cells'][$repair->id][$prio->id]['pct'])->toBe(100)
        ->and($r['cells'][$install->id][$cov->id]['state'])->toBe('weak')
        ->and($r['cells'][$install->id][$cov->id]['pct'])->toBe(0)
        ->and($r['cells'][$repair->id][$cov->id]['state'])->toBe('pending')
        ->and($r['cells'][$install->id][$prio->id] ?? null)->toBeNull();   // untested blind spot

    expect($r['summary'])->toMatchArray(['prompts' => 3, 'measured' => 2, 'cited' => 1, 'untested_cells' => 1, 'engines' => 2]);

    // The only absent-gap is Install × Clifton, with its competitor.
    expect($r['gaps'])->toHaveCount(1)
        ->and($r['gaps'][0])->toMatchArray(['service' => 'Install', 'market' => 'Clifton', 'intent' => 'Hire', 'engines_measured' => 2, 'competitors' => ['Rival Plumbing']]);

    // Columns: priority market first, then coverage.
    expect(array_column($r['columns'], 'name'))->toBe(['Union', 'Clifton']);
});

it('ranks absent-gaps priority markets first, then by competitors', function () {
    $site = Site::factory()->create();
    $svc = Service::factory()->create(['site_id' => $site->id, 'name' => 'Repair']);
    $prio = Market::factory()->priority()->create(['site_id' => $site->id, 'name' => 'Prio']);
    $cov = Market::factory()->coverage()->create(['site_id' => $site->id, 'name' => 'Cov']);

    $covGap = covPrompt($site, $svc, $cov, GeoIntent::Hire);       // coverage market
    covSnap($covGap, 'claude', cited: false, competitors: ['A', 'B']);
    $prioGap = covPrompt($site, $svc, $prio, GeoIntent::Hire);     // priority market → ranks first
    covSnap($prioGap, 'claude', cited: false);

    $gaps = app(GeoCoverage::class)->report($site)['gaps'];

    expect($gaps)->toHaveCount(2)
        ->and($gaps[0]['market'])->toBe('Prio')    // priority beats coverage even with fewer competitors
        ->and($gaps[1]['market'])->toBe('Cov');
});
