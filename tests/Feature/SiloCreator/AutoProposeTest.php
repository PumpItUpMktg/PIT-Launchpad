<?php

use App\Enums\SiloType;
use App\Integrations\Claude\ClaudeClient;
use App\SiloCreator\AutoProposer;
use App\SiloCreator\DeterministicProposer;
use Tests\Support\FakeClaudeClient;
use Tests\Support\SiloCreatorFixtures;

test('the deterministic pass yields a pillar silo per pillar service', function () {
    ['site' => $site] = SiloCreatorFixtures::catalog();

    $proposals = app(DeterministicProposer::class)->propose($site);

    $pillars = array_filter($proposals, fn ($p) => $p->type === SiloType::ServicePillar);
    $names = array_map(fn ($p) => $p->name, $pillars);

    expect($pillars)->toHaveCount(2)
        ->and($names)->toContain('Plumbing')
        ->and($names)->toContain('HVAC');
});

test('each proposed pillar carries a seeded rule_set and candidate clusters', function () {
    ['site' => $site] = SiloCreatorFixtures::catalog();

    $proposals = app(DeterministicProposer::class)->propose($site);
    $plumbing = collect($proposals)->firstWhere('name', 'Plumbing');

    expect($plumbing->ruleSet->seedTerms)->not->toBeEmpty()
        ->and($plumbing->ruleSet->seedTerms)->toContain('plumbing')
        ->and($plumbing->clusters)->not->toBeEmpty();
});

test('the topical pass proposes advisory themes via the Claude adapter', function () {
    ['site' => $site] = SiloCreatorFixtures::catalog();

    $this->app->instance(ClaudeClient::class, new FakeClaudeClient(SiloCreatorFixtures::themesJson()));

    $set = app(AutoProposer::class)->propose($site);
    $topical = $set->ofType(SiloType::Topical);

    expect($topical->count())->toBe(1)
        ->and($topical->named('Maintenance & Prevention'))->not->toBeNull();
});
