<?php

use App\Models\Market;
use App\SiloCreator\GeoNeutralValidator;
use App\SiloCreator\RuleSet;
use Tests\Support\SiloCreatorFixtures;

test('a silo containing a market city term is flagged', function () {
    ['site' => $site] = SiloCreatorFixtures::catalog();
    Market::factory()->create(['site_id' => $site->id, 'name' => 'Austin']);

    $violations = app(GeoNeutralValidator::class)->violations('Plumbing in Austin', new RuleSet, $site->id);

    expect($violations)->toContain('austin');
});

test('a geo-neutral silo passes', function () {
    ['site' => $site] = SiloCreatorFixtures::catalog();
    Market::factory()->create(['site_id' => $site->id, 'name' => 'Austin']);

    $ruleSet = new RuleSet(seedTerms: ['water', 'heater', 'repair']);

    expect(app(GeoNeutralValidator::class)->isGeoNeutral('Water Heater Repair', $ruleSet, $site->id))->toBeTrue();
});

test('a state term in the rule_set is flagged', function () {
    ['site' => $site] = SiloCreatorFixtures::catalog();

    $ruleSet = new RuleSet(seedTerms: ['texas', 'plumbing']);

    expect(app(GeoNeutralValidator::class)->violations('Plumbing', $ruleSet, $site->id))->toContain('texas');
});
