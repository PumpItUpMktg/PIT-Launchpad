<?php

use App\SiloCreator\RuleSetSeeder;
use Tests\Support\SiloCreatorFixtures;

test('rule_sets generate with seed terms and include patterns', function () {
    ['plumbing' => $plumbing] = SiloCreatorFixtures::catalog();

    $ruleSet = app(RuleSetSeeder::class)->forService($plumbing->load('problems'));

    expect($ruleSet->seedTerms)->not->toBeEmpty()
        ->and($ruleSet->includePatterns)->not->toBeEmpty()
        ->and($ruleSet->seedTerms)->toContain('water')   // from a problem phrase
        ->and($ruleSet->seedTerms)->toContain('pipes');  // from the scope
});

test('a topical rule_set seeds from the theme name and terms', function () {
    $ruleSet = app(RuleSetSeeder::class)->forTheme(
        'Maintenance & Prevention',
        ['maintenance', 'inspection'],
        ['water heater leaking'],
    );

    expect($ruleSet->seedTerms)->toContain('maintenance')
        ->and($ruleSet->includePatterns)->toContain('inspection');
});
