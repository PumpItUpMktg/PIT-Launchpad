<?php

use App\Integrations\LocalGrid\LocalGridProvider;
use App\Integrations\LocalGrid\LocalPackCompetitor;
use App\Integrations\LocalGrid\MockLocalGridProvider;
use App\Integrations\Serp\MockSerpProvider;
use App\Integrations\Serp\SerpProvider;
use App\Integrations\Serp\SerpResult;
use App\Integrations\Serp\SerpResultSet;
use App\KeywordGenerator\Pipeline\KeywordPipeline;
use App\Models\Keyword;
use App\Models\Market;
use App\Models\Service;
use App\Models\Silo;
use App\Models\Site;

test('the pipeline scores, beatability-gates and emits quick-win-ordered briefs', function () {
    $site = Site::factory()->create();
    $market = Market::factory()->priority()->create(['site_id' => $site->id]);

    $silo = Silo::factory()->servicePillar()->create([
        'site_id' => $site->id,
        'name' => 'Plumbing',
        'rule_set' => ['include_patterns' => ['water heater', 'drain', 'plumb', 'flush'], 'exclude_patterns' => []],
    ]);
    $service = Service::factory()->create(['site_id' => $site->id, 'is_most_profitable' => true, 'is_growth_priority' => true]);
    $silo->services()->attach($service->id);

    Keyword::factory()->create(['site_id' => $site->id, 'silo_id' => null, 'query' => 'water heater repair', 'intent' => 'transactional']);
    Keyword::factory()->create(['site_id' => $site->id, 'silo_id' => null, 'query' => 'how to flush a water heater', 'intent' => 'informational']);
    Keyword::factory()->create(['site_id' => $site->id, 'silo_id' => null, 'query' => 'roof replacement', 'intent' => 'transactional']);

    // Programmed providers (vendor-deferred).
    $serp = (new MockSerpProvider)
        ->setMetrics('water heater repair', 6000, 35, ['tankless', 'water heater install'])
        ->setMetrics('how to flush a water heater', 3000, 15, ['sediment flush'])
        ->setMetrics('roof replacement', 9000, 60, []);
    $serp->setResults('how to flush a water heater', new SerpResultSet('how to flush a water heater', [
        new SerpResult(1, 'https://localplumber.com/guide', 'localplumber.com'),
        new SerpResult(2, 'https://anotherlocal.com/guide', 'anotherlocal.com'),
    ]));
    $this->app->instance(SerpProvider::class, $serp);

    $grid = (new MockLocalGridProvider)->setGrid('water heater repair', $market->id, 6.0, 0.3, 0.7, [
        new LocalPackCompetitor('Austin Plumb Pros', 'austinplumbpros.com'),
        new LocalPackCompetitor('Hill Country', 'hillcountryplumbing.com'),
    ]);
    $this->app->instance(LocalGridProvider::class, $grid);

    $result = app(KeywordPipeline::class)->run($site);

    // Two bucketable keywords scored; "roof replacement" is an unbucketed gap.
    expect($result->scored)->toHaveCount(2)
        ->and(Keyword::withoutGlobalScopes()->where('query', 'roof replacement')->value('status'))->toBe('gap');

    // Scores were written back.
    expect(Keyword::withoutGlobalScopes()->where('query', 'water heater repair')->value('opportunity_score'))->not->toBeNull();

    // Briefs are quick-wins ordered (descending).
    $briefs = $result->briefs->all();
    expect($result->briefs->count())->toBeGreaterThanOrEqual(1);

    $quickWins = array_map(fn ($b) => $b->quickWin, $briefs);
    $sorted = $quickWins;
    rsort($sorted);
    expect($quickWins)->toBe($sorted);
});
