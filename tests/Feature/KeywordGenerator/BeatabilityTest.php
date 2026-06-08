<?php

use App\Enums\BeatabilityLane;
use App\Enums\CompetitorClass;
use App\Enums\IntentLevel;
use App\Integrations\LocalGrid\LocalGridProvider;
use App\Integrations\LocalGrid\LocalPackCompetitor;
use App\Integrations\LocalGrid\MockLocalGridProvider;
use App\Integrations\Serp\MockSerpProvider;
use App\Integrations\Serp\SerpProvider;
use App\Integrations\Serp\SerpResult;
use App\Integrations\Serp\SerpResultSet;
use App\KeywordGenerator\Beatability\BeatabilityEngine;
use App\KeywordGenerator\Beatability\CompetitorClassifier;
use App\KeywordGenerator\Beatability\LaneClassifier;
use App\Models\Market;
use App\Models\PositionSnapshot;
use App\Models\Site;

test('the lane classifier routes local vs organic intent', function () {
    $lanes = new LaneClassifier;

    expect($lanes->classify('water heater repair', IntentLevel::Transactional))->toBe(BeatabilityLane::LocalPack)
        ->and($lanes->classify('how to flush a water heater', IntentLevel::Informational))->toBe(BeatabilityLane::Organic)
        ->and($lanes->classify('plumber near me', IntentLevel::Informational))->toBe(BeatabilityLane::LocalPack);
});

test('the competitor classifier identifies the lane beneath the big players', function () {
    $c = new CompetitorClassifier;

    expect($c->classify('yelp.com'))->toBe(CompetitorClass::AggregatorDirectory)
        ->and($c->classify('homedepot.com'))->toBe(CompetitorClass::NationalBigBox)
        ->and($c->classify('wikipedia.org'))->toBe(CompetitorClass::EditorialGov)
        ->and($c->classify('austin.gov'))->toBe(CompetitorClass::EditorialGov)
        ->and($c->classify('austinplumbingpros.com'))->toBe(CompetitorClass::LocalCompetitor);
});

test('local-pack beatability is high against local competitors and parks against nationals', function () {
    $site = Site::factory()->create();
    $market = Market::factory()->priority()->create(['site_id' => $site->id]);

    $grid = new MockLocalGridProvider;
    $grid->setGrid('drain cleaning', $market->id, 8.0, 0.2, 0.6, [
        new LocalPackCompetitor('Austin Drain Pros', 'austindrainpros.com'),
        new LocalPackCompetitor('Hill Country Plumbing', 'hillcountryplumbing.com'),
        new LocalPackCompetitor('Capital Rooter', 'capitalrooter.com'),
    ]);
    $grid->setGrid('emergency plumber', $market->id, 9.0, 0.0, 0.4, [
        new LocalPackCompetitor('Home Depot', 'homedepot.com'),
        new LocalPackCompetitor('Angi', 'angi.com'),
        new LocalPackCompetitor('Yelp', 'yelp.com'),
    ]);
    $this->app->instance(LocalGridProvider::class, $grid);

    $engine = app(BeatabilityEngine::class);

    $local = $engine->assess($site, 'drain cleaning', IntentLevel::Transactional, $market);
    expect($local->lane)->toBe(BeatabilityLane::LocalPack)
        ->and($local->marketId)->toBe($market->id)
        ->and($local->score)->toBeGreaterThan(0.7)
        ->and($local->parked)->toBeFalse();

    $national = $engine->assess($site, 'emergency plumber', IntentLevel::Transactional, $market);
    expect($national->score)->toBeLessThan(0.2)
        ->and($national->parked)->toBeTrue();

    // A strategic long-play overrides parking.
    $longPlay = $engine->assess($site, 'emergency plumber', IntentLevel::Transactional, $market, longPlay: true);
    expect($longPlay->parked)->toBeFalse();
});

test('organic beatability is gated by site authority and self-calibrates from positions', function () {
    $site = Site::factory()->create();

    $serp = new MockSerpProvider;
    $serp->setResults('how to flush a water heater', new SerpResultSet('how to flush a water heater', [
        new SerpResult(1, 'https://localplumber.com/guide', 'localplumber.com'),
        new SerpResult(2, 'https://anotherlocal.com/guide', 'anotherlocal.com'),
    ]));
    $this->app->instance(SerpProvider::class, $serp);

    $newSite = app(BeatabilityEngine::class)->assess($site, 'how to flush a water heater', IntentLevel::Informational);
    expect($newSite->lane)->toBe(BeatabilityLane::Organic);

    // Self-calibration: top-3 organic history lifts the site authority tier.
    PositionSnapshot::factory()->count(5)->create([
        'site_id' => $site->id,
        'lane' => BeatabilityLane::Organic,
        'rank' => 3,
        'market_id' => null,
    ]);

    $establishedSite = app(BeatabilityEngine::class)->assess($site, 'how to flush a water heater', IntentLevel::Informational);
    expect($establishedSite->score)->toBeGreaterThan($newSite->score);
});
