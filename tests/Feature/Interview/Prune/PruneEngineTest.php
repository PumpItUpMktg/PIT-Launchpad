<?php

use App\Enums\PruneOutcome;
use App\Enums\SpokeGranularity;
use App\Enums\SpokePageType;
use App\Enums\SpokeStatus;
use App\Enums\SpokeTag;
use App\Interview\Prune\PruneEngine;
use App\Models\Scopes\SiteScope;
use App\Models\SiloBlueprint;
use App\Models\Site;
use App\Models\Spoke;

function pruneSite(): Site
{
    $site = Site::factory()->create();
    $bp = SiloBlueprint::factory()->create(['site_id' => $site->id]);

    $make = fn (array $attrs) => Spoke::factory()->create(array_merge([
        'site_id' => $site->id,
        'silo_blueprint_id' => $bp->id,
        'silo' => 'Sump Pumps',
        'status' => SpokeStatus::Candidate,
        'granularity' => SpokeGranularity::OwnPage,
    ], $attrs));

    $make(['name' => 'Sump Pumps', 'is_pillar' => true, 'tag' => SpokeTag::Core, 'page_type' => SpokePageType::Service]);
    $make(['name' => 'Sump Pump Installation', 'tag' => SpokeTag::Core, 'page_type' => SpokePageType::Service, 'volume' => 300]);
    $make(['name' => 'Why Is My Basement Wet?', 'tag' => SpokeTag::Core, 'page_type' => SpokePageType::Content]);
    $make(['name' => 'Gutter Installation', 'tag' => SpokeTag::Connecting, 'page_type' => SpokePageType::Service, 'connection_note' => 'gutters cause basement water', 'volume' => 90]);
    $make(['name' => 'Curtain Drain', 'tag' => SpokeTag::Adjacent, 'page_type' => SpokePageType::Service, 'volume' => 20]);
    // a separate, thin silo to fold
    $make(['name' => 'Sewage Pumps', 'silo' => 'Sewage Pumps', 'is_pillar' => true, 'tag' => SpokeTag::Core, 'page_type' => SpokePageType::Service]);
    $make(['name' => 'Sewage Pump Repair', 'silo' => 'Sewage Pumps', 'tag' => SpokeTag::Core, 'page_type' => SpokePageType::Service]);
    $make(['name' => 'General Plumbing', 'silo' => 'Out of Lane', 'tag' => SpokeTag::Fringe, 'page_type' => SpokePageType::Service]);

    return $site;
}

function spk(Site $site, string $name): Spoke
{
    return Spoke::withoutGlobalScope(SiteScope::class)->where('site_id', $site->id)->where('name', $name)->first();
}

test('the plan groups by silo, volume-sorts within, and summarizes', function () {
    $plan = app(PruneEngine::class)->plan(pruneSite());

    $bySilo = $plan->bySilo();
    expect(array_keys($bySilo))->toContain('Sump Pumps', 'Sewage Pumps')
        ->and($plan->fringe())->toHaveCount(1);

    $names = array_map(fn ($r) => $r->name, $bySilo['Sump Pumps']);
    expect($names[0])->toBe('Sump Pumps')                       // pillar first
        ->and(array_search('Gutter Installation', $names, true))
        ->toBeLessThan(array_search('Curtain Drain', $names, true)); // 90 before 20

    $summary = $plan->siloSummaries()['Sump Pumps'];
    expect($summary['core'])->toBe(3)->and($summary['lean_ins'])->toBe(2)->and($summary['lean_in_volume'])->toBe(110);
});

test('applySpokes routes per the table and a capture converts a service spoke to content', function () {
    $site = pruneSite();

    app(PruneEngine::class)->applySpokes($site, [
        'Sump Pump Installation' => ['outcome' => 'offer'],
        'Gutter Installation' => ['outcome' => 'capture'],
        'Curtain Drain' => 'skip', // bare-string shorthand
    ]);

    expect(spk($site, 'Sump Pump Installation')->status)->toBe(SpokeStatus::Offered)
        ->and(spk($site, 'Gutter Installation')->status)->toBe(SpokeStatus::Content)
        ->and(spk($site, 'Gutter Installation')->page_type)->toBe(SpokePageType::Content)
        ->and(spk($site, 'Curtain Drain')->status)->toBe(SpokeStatus::Skipped);
});

test('a connecting spoke can be re-tagged to core at confirm time', function () {
    $site = pruneSite();

    app(PruneEngine::class)->applySpokes($site, [
        'Gutter Installation' => ['outcome' => 'offer', 'tag' => 'core'],
    ]);

    expect(spk($site, 'Gutter Installation')->tag)->toBe(SpokeTag::Core)
        ->and(spk($site, 'Gutter Installation')->status)->toBe(SpokeStatus::Offered);
});

test('a granularity override is applied alongside the route', function () {
    $site = pruneSite();

    app(PruneEngine::class)->applySpokes($site, [
        'Curtain Drain' => ['outcome' => 'offer', 'granularity' => 'folded'],
    ]);

    expect(spk($site, 'Curtain Drain')->granularity)->toBe(SpokeGranularity::Folded);
});

test('foldSilo collapses a thin silo under another pillar', function () {
    $site = pruneSite();

    $moved = app(PruneEngine::class)->foldSilo($site, 'Sewage Pumps', 'Sump Pumps');

    expect($moved)->toBe(2)
        ->and(spk($site, 'Sewage Pump Repair')->silo)->toBe('Sump Pumps')
        ->and(spk($site, 'Sewage Pumps')->silo)->toBe('Sump Pumps')
        ->and(spk($site, 'Sewage Pumps')->is_pillar)->toBeFalse(); // former pillar demoted
});

test('renameSilo renames the grouping and the pillar spoke', function () {
    $site = pruneSite();

    app(PruneEngine::class)->renameSilo($site, 'Sump Pumps', 'Pumps');

    expect(spk($site, 'Pumps')->is_pillar)->toBeTrue()
        ->and(spk($site, 'Sump Pump Installation')->silo)->toBe('Pumps');
});

test('a decision-set applies spokes + silo fold/rename in one transaction', function () {
    $site = pruneSite();

    $result = app(PruneEngine::class)->applyDecisionSet($site, [
        'spokes' => [
            'Gutter Installation' => ['outcome' => 'offer', 'tag' => 'core'],
            'Curtain Drain' => ['outcome' => 'skip'],
        ],
        'silos' => [
            'Sump Pumps' => ['rename' => 'Pumps'],
            'Sewage Pumps' => ['fold_into' => 'Pumps'],
        ],
    ]);

    expect($result['spokes_applied'])->toBe(2)
        ->and($result['silos_renamed'])->toBe(1)
        ->and($result['silos_folded'])->toBe(1)
        ->and(spk($site, 'Sewage Pump Repair')->silo)->toBe('Pumps') // folded into the renamed pillar
        ->and(spk($site, 'Gutter Installation')->tag)->toBe(SpokeTag::Core);
});

test('confirm is gated until every non-fringe candidate is decided, then stamps the blueprint', function () {
    $site = pruneSite();
    $engine = app(PruneEngine::class);

    expect($engine->confirm($site)['confirmed'])->toBeFalse();

    $engine->acceptCore($site); // pillars + core (incl. sewage)
    $engine->applySpokes($site, ['Gutter Installation' => 'capture', 'Curtain Drain' => 'skip']);

    expect($engine->confirm($site)['confirmed'])->toBeTrue()
        ->and(SiloBlueprint::withoutGlobalScope(SiteScope::class)->where('site_id', $site->id)->value('confirmed_at'))->not->toBeNull();
});

test('accept-core offers core service spokes and keeps core content as capture; lean-ins untouched', function () {
    $site = pruneSite();

    app(PruneEngine::class)->acceptCore($site);

    expect(spk($site, 'Sump Pump Installation')->status)->toBe(SpokeStatus::Offered)
        ->and(spk($site, 'Why Is My Basement Wet?')->status)->toBe(SpokeStatus::Content)
        ->and(spk($site, 'Gutter Installation')->status)->toBe(SpokeStatus::Candidate);
});

test('applySpokes reports unmatched keys and bad outcomes', function () {
    $site = pruneSite();

    $result = app(PruneEngine::class)->applySpokes($site, ['Nope' => 'offer', 'Curtain Drain' => 'bogus']);

    expect($result['applied'])->toBe(0)
        ->and($result['unmatched'])->toContain('Nope')
        ->and(collect($result['unmatched'])->contains(fn ($u) => str_contains($u, 'bad outcome')))->toBeTrue();
});

test('the routing enum maps outcomes to status + page type', function () {
    expect(PruneOutcome::Offer->status())->toBe(SpokeStatus::Offered)
        ->and(PruneOutcome::Capture->status())->toBe(SpokeStatus::Content)
        ->and(PruneOutcome::Capture->pageType())->toBe(SpokePageType::Content)
        ->and(PruneOutcome::Skip->pageType())->toBeNull();
});
