<?php

use App\Integrations\Keywords\KeywordIdeaProvider;
use App\Integrations\Keywords\MockKeywordIdeaProvider;
use App\KeywordGenerator\Corpus\CorpusAccumulator;
use App\Models\KeywordCorpus;
use App\Models\Service;
use App\Models\SiloBlueprint;
use App\Models\Site;

beforeEach(function () {
    $this->app->instance(KeywordIdeaProvider::class, new MockKeywordIdeaProvider);
});

function seededSite(): Site
{
    $site = Site::factory()->create(['brand_name' => 'Sump Pump Gurus']);
    SiloBlueprint::withoutGlobalScopes()->create(['site_id' => $site->id, 'trade' => 'french drain']);
    Service::factory()->create(['site_id' => $site->id, 'name' => 'Curtain Drain']);

    return $site;
}

it('accumulates a deduped, geo-neutral, intent-tagged corpus from seeds', function () {
    $site = seededSite();

    $result = app(CorpusAccumulator::class)->accumulate($site);

    $rows = KeywordCorpus::withoutGlobalScopes()->where('site_id', $site->id)->get();

    // Seeds expanded into a corpus (trade + curtain drain, plus their modifier ideas).
    expect($result->total)->toBe($rows->count())
        ->and($result->total)->toBeGreaterThan(4)
        ->and($result->seedCount)->toBe(2);

    // Expansion ideas landed with metrics + intent.
    $cost = $rows->firstWhere('canonical', 'french drain cost');
    expect($cost)->not->toBeNull()
        ->and($cost->volume)->toBeGreaterThan(0)
        ->and($cost->intent)->not->toBeNull()
        ->and($cost->seed_term)->toBe('french drain');

    // The geo-modified idea ("french drain near me") was dropped, never stored.
    expect($rows->firstWhere('canonical', 'french drain near me'))->toBeNull()
        ->and($result->geoFiltered)->toBeGreaterThanOrEqual(2); // one "near me" per seed
});

it('is re-runnable — refreshes metrics and adds terms without wiping operator dispositions', function () {
    $site = seededSite();
    app(CorpusAccumulator::class)->accumulate($site);

    // Operator dismisses a term + clustering pins one; both must survive re-accumulation.
    $dismissed = KeywordCorpus::withoutGlobalScopes()->where('site_id', $site->id)->firstWhere('canonical', 'french drain cost');
    $dismissed->forceFill(['disposition' => 'dismissed', 'cluster_id' => '01JCLUSTERXXXXXXXXXXXXXXXX'])->save();
    $countBefore = KeywordCorpus::withoutGlobalScopes()->where('site_id', $site->id)->count();

    $result = app(CorpusAccumulator::class)->accumulate($site);

    $dismissed->refresh();
    expect($dismissed->disposition)->toBe('dismissed')                       // untouched
        ->and($dismissed->cluster_id)->toBe('01JCLUSTERXXXXXXXXXXXXXXXX')     // untouched
        ->and($result->added)->toBe(0)                                        // nothing new the 2nd run
        ->and($result->refreshed)->toBeGreaterThan(0)
        ->and(KeywordCorpus::withoutGlobalScopes()->where('site_id', $site->id)->count())->toBe($countBefore); // no dupes
});

it('caps the corpus to the breadth guardrail', function () {
    config()->set('launchpad.keyword_first.total_cap', 3);
    $site = seededSite();

    $result = app(CorpusAccumulator::class)->accumulate($site);

    expect($result->capped)->toBeTrue()
        ->and($result->total)->toBe(3);
});

it('the command reports the corpus size', function () {
    $site = seededSite();

    $this->artisan('launchpad:accumulate-corpus', ['--site' => $site->id])
        ->expectsOutputToContain('Corpus for Sump Pump Gurus')
        ->assertSuccessful();
});
