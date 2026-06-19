<?php

use App\Client\PagePlan;
use App\Enums\SpokeGranularity;
use App\Enums\SpokePageType;
use App\Enums\SpokeStatus;
use App\Enums\SpokeTag;
use App\Models\SiloBlueprint;
use App\Models\Site;
use App\Models\Spoke;

function ppSpoke(Site $site, SiloBlueprint $bp, array $attrs): Spoke
{
    return Spoke::factory()->create(array_merge([
        'site_id' => $site->id,
        'silo_blueprint_id' => $bp->id,
        'status' => SpokeStatus::Candidate,
        'page_type' => SpokePageType::Service,
        'tag' => SpokeTag::Core,
        'granularity' => SpokeGranularity::OwnPage,
        'volume' => 0,
    ], $attrs));
}

/**
 * An arranged tree: Sump Pumps (pillar + a core with a folded section); Backup Power demoted
 * to a sub-hub under it (pillar + a core); plus a skipped + a fringe spoke that must not show.
 *
 * @return array{site: Site}
 */
function ppArranged(): array
{
    $site = Site::factory()->create();
    $bp = SiloBlueprint::factory()->create(['site_id' => $site->id]);

    $sumpPillar = ppSpoke($site, $bp, ['silo' => 'Sump Pumps', 'name' => 'Sump Pumps', 'is_pillar' => true]);
    $core = ppSpoke($site, $bp, ['silo' => 'Sump Pumps', 'name' => 'Battery Backup Sump Pump', 'primary_keyword' => 'battery backup', 'volume' => 300]);
    ppSpoke($site, $bp, ['silo' => 'Sump Pumps', 'name' => 'Water-Powered Backup', 'granularity' => SpokeGranularity::Folded, 'fold_into_id' => $core->id, 'volume' => 20]);

    // Backup Power: a demoted sub-hub under Sump Pumps, with its own core.
    ppSpoke($site, $bp, ['silo' => 'Backup Power', 'name' => 'Backup Power', 'is_pillar' => true, 'is_sub_hub' => true, 'parent_silo_id' => $sumpPillar->id]);
    ppSpoke($site, $bp, ['silo' => 'Backup Power', 'name' => 'Battery Standby', 'primary_keyword' => 'battery standby', 'volume' => 40]);

    // Excluded from the plan.
    ppSpoke($site, $bp, ['silo' => 'Sump Pumps', 'name' => 'Dropped Thing', 'status' => SpokeStatus::Skipped, 'volume' => 999]);
    ppSpoke($site, $bp, ['silo' => 'Out of Lane', 'name' => 'Roofing', 'tag' => SpokeTag::Fringe, 'volume' => 999]);

    return ['site' => $site];
}

test('the page plan groups the arranged inventory by top-level silo with lead-upside', function () {
    ['site' => $site] = ppArranged();

    $plan = app(PagePlan::class)->for($site);

    // Sub-hub rolls up: one top-level silo, four pages (Sump Pumps + Backup Power hubs, two cores).
    expect($plan['totals'])->toBe(['silos' => 1, 'pages' => 4, 'sections' => 1, 'volume' => 360])
        ->and($plan['silos'][0]['name'])->toBe('Sump Pumps');

    $names = collect($plan['silos'][0]['pages'])->pluck('name');
    expect($names)->toContain('Battery Standby')   // the sub-hub's core rolled up
        ->toContain('Backup Power')                // the sub-hub itself, as a hub page
        ->not->toContain('Dropped Thing')          // skipped excluded
        ->not->toContain('Roofing');               // fringe excluded
});

test('a folded spoke rides along as a section of its home page, never its own page', function () {
    ['site' => $site] = ppArranged();

    $plan = app(PagePlan::class)->for($site);
    $home = collect($plan['silos'][0]['pages'])->firstWhere('name', 'Battery Backup Sump Pump');

    expect(collect($plan['silos'][0]['pages'])->pluck('name'))->not->toContain('Water-Powered Backup')
        ->and($home['sections'])->toHaveCount(1)
        ->and($home['sections'][0]['name'])->toBe('Water-Powered Backup');
});

test('a hub page is marked and sorted first within its silo', function () {
    ['site' => $site] = ppArranged();

    $plan = app(PagePlan::class)->for($site);

    expect($plan['silos'][0]['pages'][0]['kind'])->toBe('hub');
});

test('an empty plan is returned when the site has no blueprint', function () {
    $site = Site::factory()->create();

    expect(app(PagePlan::class)->for($site))
        ->toBe(['silos' => [], 'totals' => ['silos' => 0, 'pages' => 0, 'sections' => 0, 'volume' => 0]]);
});
