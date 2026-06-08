<?php

use App\Models\Silo;
use App\SiloCreator\InternalLinking;
use Tests\Support\SiloCreatorFixtures;

test('the linking model derives siblings and persists cross-silo links', function () {
    ['site' => $site] = SiloCreatorFixtures::catalog();

    $parent = Silo::factory()->servicePillar()->create(['site_id' => $site->id]);
    $childA = Silo::factory()->topical()->create(['site_id' => $site->id, 'parent_silo_id' => $parent->id]);
    $childB = Silo::factory()->topical()->create(['site_id' => $site->id, 'parent_silo_id' => $parent->id]);
    $other = Silo::factory()->servicePillar()->create(['site_id' => $site->id]);

    $linking = app(InternalLinking::class);
    $linking->register($parent, $other);

    $parentModel = $linking->modelFor($parent);
    expect($parentModel['children'])->toContain($childA->id)
        ->and($parentModel['children'])->toContain($childB->id)
        ->and($parentModel['cross_silo'])->toContain($other->id);

    $childModel = $linking->modelFor($childA);
    expect($childModel['siblings'])->toContain($childB->id)
        ->and($childModel['siblings'])->not->toContain($childA->id);
});
