<?php

use App\Models\Site;
use App\Onboarding\IntakeCollector;
use Illuminate\Support\Facades\Log;

it('drops an out-of-service-area market coordinate to null and logs the rejection', function () {
    Log::spy();
    $site = Site::factory()->create();

    // The exact prod corruption: a South-Pacific pair that centred a local grid over open ocean.
    $markets = app(IntakeCollector::class)->saveMarkets($site, [
        ['name' => 'Ocean Error', 'tier' => 'coverage', 'lat' => -29.6238960, 'lng' => -175.4491260],
    ]);

    $m = $markets->first();
    expect($m->lat)->toBeNull()->and($m->lng)->toBeNull();
    Log::shouldHaveReceived('warning')->once();
});

it('keeps a valid US market coordinate', function () {
    $site = Site::factory()->create();

    $m = app(IntakeCollector::class)->saveMarkets($site, [
        ['name' => 'Newark', 'tier' => 'coverage', 'lat' => 40.7244717, 'lng' => -74.1725407],
    ])->first();

    expect((float) $m->lat)->toEqualWithDelta(40.7244717, 1e-7)
        ->and((float) $m->lng)->toEqualWithDelta(-74.1725407, 1e-7);
});

it('leaves a market with no coordinate null WITHOUT a warning (a gap is not corruption)', function () {
    Log::spy();
    $site = Site::factory()->create();

    $m = app(IntakeCollector::class)->saveMarkets($site, [
        ['name' => 'No Geo', 'tier' => 'coverage'],
    ])->first();

    expect($m->lat)->toBeNull()->and($m->lng)->toBeNull()
        ->and($m->hasValidGeo())->toBeFalse();
    Log::shouldNotHaveReceived('warning');
});
