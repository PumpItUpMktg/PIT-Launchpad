<?php

use App\Enums\SiloType;
use App\Integrations\Claude\ClaudeClient;
use App\SiloCreator\AutoProposer;
use App\SiloCreator\ViabilityGuard;
use Tests\Support\FakeClaudeClient;
use Tests\Support\SiloCreatorFixtures;

test('the viability guard rejects an under-supported theme', function () {
    $guard = new ViabilityGuard(threshold: 3);

    expect($guard->isViable(3))->toBeTrue()
        ->and($guard->isViable(1))->toBeFalse();
});

test('an under-supported theme is dropped from the proposals', function () {
    ['site' => $site] = SiloCreatorFixtures::catalog();

    $this->app->instance(ClaudeClient::class, new FakeClaudeClient(SiloCreatorFixtures::themesJson()));

    $topical = app(AutoProposer::class)->propose($site)->ofType(SiloType::Topical);

    // "Smart Thermostats" has a single match and must not survive the guard.
    expect($topical->named('Smart Thermostats'))->toBeNull()
        ->and($topical->count())->toBe(1);
});
