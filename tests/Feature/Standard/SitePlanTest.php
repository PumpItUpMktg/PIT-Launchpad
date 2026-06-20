<?php

use App\Enums\ProofType;
use App\Enums\SpokeGranularity;
use App\Enums\SpokeStatus;
use App\Enums\StandardPageType;
use App\Models\CoverageArea;
use App\Models\ProofItem;
use App\Models\SiloBlueprint;
use App\Models\Site;
use App\Models\Spoke;
use App\Standard\SitePlan;
use App\Standard\StandardPages;

test('the site plan renders all four sources: fixed, offerable optionals, service, locations', function () {
    $site = Site::factory()->create();
    $bp = SiloBlueprint::factory()->create(['site_id' => $site->id]);
    Spoke::factory()->create(['site_id' => $site->id, 'silo_blueprint_id' => $bp->id, 'silo' => 'Pumps', 'name' => 'Pumps', 'is_pillar' => true, 'status' => SpokeStatus::Offered]);
    Spoke::factory()->create(['site_id' => $site->id, 'silo_blueprint_id' => $bp->id, 'silo' => 'Pumps', 'name' => 'Install', 'status' => SpokeStatus::Offered, 'granularity' => SpokeGranularity::OwnPage, 'volume' => 200]);
    CoverageArea::factory()->create(['site_id' => $site->id, 'name' => 'Newark', 'page_selected' => true, 'population' => 300000]);

    ProofItem::factory()->create(['site_id' => $site->id, 'type' => ProofType::ReviewAggregate]);
    app(StandardPages::class)->setAccepted($site, StandardPageType::Reviews, true);

    $plan = app(SitePlan::class)->for($site->fresh());

    expect($plan['fixed'])->toHaveCount(6)
        ->and(collect($plan['optionals'])->firstWhere('type', 'reviews')['accepted'])->toBeTrue()
        ->and($plan['service'])->not->toBeEmpty()
        ->and($plan['service'][0]['name'])->toBe('Pumps')
        ->and($plan['locations']['count'])->toBe(1)
        ->and($plan['locations']['sample'])->toContain('Newark');
});
