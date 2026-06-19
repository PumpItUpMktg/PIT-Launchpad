<?php

use App\Enums\ArrangeFlagType;
use App\Enums\ArrangementSource;
use App\Enums\SpokeGranularity;
use App\Enums\SpokePageType;
use App\Enums\SpokeStatus;
use App\Enums\SpokeTag;
use App\Integrations\Embedding\EmbeddingProvider;
use App\Interview\Arrange\AutoArrangeRunner;
use App\Interview\Arrange\FlagResolver;
use App\Interview\Arrange\FoldTargetAssigner;
use App\Interview\Arrange\SubHubDemoter;
use App\Interview\Prune\PruneEngine;
use App\Models\ArrangementFlag;
use App\Models\Scopes\SiteScope;
use App\Models\SiloBlueprint;
use App\Models\Site;
use App\Models\Spoke;

/**
 * Spoke vectors cluster Backup Power + Pump Protection into the Sump Pumps magnet (Pass C),
 * while keeping every page's keyword distinct (no Pass D noise) and below the dedup line.
 */
class FlagReviewFake implements EmbeddingProvider
{
    public function embed(string $text): array
    {
        $t = mb_strtolower($text);

        return match (true) {
            str_contains($t, 'battery backup sump pump') => [1.0, 0.0, 0.0, 0.0, 0.0, 0.0],
            str_contains($t, 'battery') => [0.8, 0.0, 0.0, 0.0, 0.0, 0.6],       // Backup Power spokes / "battery backup" kw
            str_contains($t, 'sump pit') => [0.0, 1.0, 0.0, 0.0, 0.0, 0.0],
            str_contains($t, 'monitoring'), str_contains($t, 'protection plan') => [0.0, 0.8, 0.0, 0.0, 0.0, 0.6],
            str_contains($t, 'sump pumps') => [0.0, 0.0, 1.0, 0.0, 0.0, 0.0],    // pillar keyword
            str_contains($t, 'backup power') => [0.0, 0.0, 0.0, 1.0, 0.0, 0.0],
            str_contains($t, 'pump protection') => [0.0, 0.0, 0.0, 0.0, 1.0, 0.0],
            default => [0.0, 0.0, 0.0, 0.0, 0.0, 1.0],
        };
    }
}

beforeEach(function () {
    $this->fake = new FlagReviewFake;
    app()->instance(EmbeddingProvider::class, $this->fake);
});

function frSpoke(Site $site, SiloBlueprint $bp, array $attrs): Spoke
{
    return Spoke::factory()->create(array_merge([
        'site_id' => $site->id,
        'silo_blueprint_id' => $bp->id,
        'status' => SpokeStatus::Candidate,
        'page_type' => SpokePageType::Service,
        'tag' => SpokeTag::Core,
        'head_keyword' => '',
        'granularity' => SpokeGranularity::OwnPage,
    ], $attrs));
}

function frspk(Site $site, string $name): Spoke
{
    return Spoke::withoutGlobalScope(SiteScope::class)->where('site_id', $site->id)->where('name', $name)->first();
}

function frSite(Site $site): void
{
    $bp = SiloBlueprint::factory()->create(['site_id' => $site->id]);
    frSpoke($site, $bp, ['silo' => 'Sump Pumps', 'name' => 'Sump Pumps', 'is_pillar' => true]);
    frSpoke($site, $bp, ['silo' => 'Sump Pumps', 'name' => 'Battery Backup Sump Pump', 'head_keyword' => 'battery backup', 'volume' => 300]);
    frSpoke($site, $bp, ['silo' => 'Sump Pumps', 'name' => 'Sump Pit Basin', 'head_keyword' => 'sump pit', 'volume' => 150]);
    frSpoke($site, $bp, ['silo' => 'Backup Power', 'name' => 'Backup Power', 'is_pillar' => true]);
    frSpoke($site, $bp, ['silo' => 'Backup Power', 'name' => 'Battery Backup System', 'head_keyword' => 'battery backup system', 'volume' => 40, 'tag' => SpokeTag::Adjacent, 'granularity' => SpokeGranularity::Folded]);
    frSpoke($site, $bp, ['silo' => 'Backup Power', 'name' => 'Battery Standby', 'head_keyword' => 'battery standby', 'volume' => 30, 'tag' => SpokeTag::Adjacent, 'granularity' => SpokeGranularity::Folded]);
    frSpoke($site, $bp, ['silo' => 'Pump Protection', 'name' => 'Pump Protection', 'is_pillar' => true]);
    frSpoke($site, $bp, ['silo' => 'Pump Protection', 'name' => 'Sump Pump Monitoring', 'head_keyword' => 'monitoring', 'volume' => 25, 'tag' => SpokeTag::Connecting, 'granularity' => SpokeGranularity::Folded]);
    frSpoke($site, $bp, ['silo' => 'Pump Protection', 'name' => 'Pump Protection Plan', 'head_keyword' => 'protection plan', 'volume' => 20, 'tag' => SpokeTag::Connecting, 'granularity' => SpokeGranularity::Folded]);
}

function flagResolver(EmbeddingProvider $fake): FlagResolver
{
    return new FlagResolver(new SubHubDemoter($fake, new FoldTargetAssigner(0.70, 0.05)), app(PruneEngine::class));
}

test('the runner persists the flag list (replace-on-run, not duplicated)', function () {
    $site = Site::factory()->create();
    frSite($site);

    $result = app(AutoArrangeRunner::class)->run($site);

    // This fixture produces exactly the two sub-hub demotions (Backup Power + Pump Protection).
    expect($result->flagsOfType(ArrangeFlagType::SubHubDemotion))->toHaveCount(2)
        ->and(ArrangementFlag::query()->where('site_id', $site->id)->count())->toBe(2);

    app(AutoArrangeRunner::class)->run($site); // re-run

    expect(ArrangementFlag::query()->where('site_id', $site->id)->count())->toBe(2); // replaced, not 4
});

test('accepting a sub-hub demotion flag demotes the silo and clears the flag', function () {
    $site = Site::factory()->create();
    frSite($site);
    app(AutoArrangeRunner::class)->run($site);

    $flag = ArrangementFlag::query()->where('site_id', $site->id)
        ->where('spoke_id', frspk($site, 'Backup Power')->id)->first();

    expect(flagResolver($this->fake)->accept($site, $flag))->toBeTrue()
        ->and(frspk($site, 'Backup Power')->is_sub_hub)->toBeTrue()
        ->and(frspk($site, 'Backup Power')->parent_silo_id)->toBe(frspk($site, 'Sump Pumps')->id)
        ->and(ArrangementFlag::query()->whereKey($flag->id)->exists())->toBeFalse();
});

test('dismissing a sub-hub demotion leaves it separate, confirms it, and it does not re-flag', function () {
    $site = Site::factory()->create();
    frSite($site);
    app(AutoArrangeRunner::class)->run($site);

    $flag = ArrangementFlag::query()->where('site_id', $site->id)
        ->where('spoke_id', frspk($site, 'Backup Power')->id)->first();

    expect(flagResolver($this->fake)->dismiss($site, $flag))->toBeTrue()
        ->and(frspk($site, 'Backup Power')->is_sub_hub)->toBeFalse()                 // left separate
        ->and(frspk($site, 'Backup Power')->arrangement_source)->toBe(ArrangementSource::Confirmed);

    // a re-run must not re-flag the dismissed silo
    app(AutoArrangeRunner::class)->run($site);
    expect(ArrangementFlag::query()->where('site_id', $site->id)->where('spoke_id', frspk($site, 'Backup Power')->id)->exists())->toBeFalse();
});

test('FlagResolver confirms a keyword flag (own keyword provenance)', function () {
    $site = Site::factory()->create();
    $bp = SiloBlueprint::factory()->create(['site_id' => $site->id]);
    $core = frSpoke($site, $bp, ['silo' => 'A', 'name' => 'Core', 'head_keyword' => 'thing']);

    $flag = ArrangementFlag::query()->create([
        'site_id' => $site->id, 'spoke_id' => $core->id, 'type' => ArrangeFlagType::KeywordCollision,
        'message' => 'x', 'candidates' => [], 'score' => 0.95,
    ]);

    expect(flagResolver($this->fake)->accept($site, $flag))->toBeTrue()
        ->and($core->refresh()->keyword_source)->toBe(ArrangementSource::Confirmed)
        ->and($core->arrangement_source)->toBeNull(); // structural provenance untouched
});

test('accepting a dedup flag confirms the winner home and its folded child', function () {
    $site = Site::factory()->create();
    $bp = SiloBlueprint::factory()->create(['site_id' => $site->id]);
    $winner = frSpoke($site, $bp, ['silo' => 'A', 'name' => 'Winner', 'flagged' => true]);
    $loser = frSpoke($site, $bp, ['silo' => 'A', 'name' => 'Loser', 'granularity' => SpokeGranularity::Folded, 'fold_into_id' => $winner->id]);

    $flag = ArrangementFlag::query()->create([
        'site_id' => $site->id, 'spoke_id' => $winner->id, 'type' => ArrangeFlagType::DedupAmbiguous,
        'message' => 'x', 'candidates' => [], 'alternative' => [], 'score' => 0.9,
    ]);

    expect(flagResolver($this->fake)->accept($site, $flag))->toBeTrue()
        ->and($winner->refresh()->arrangement_source)->toBe(ArrangementSource::Confirmed)
        ->and($winner->flagged)->toBeFalse()                                      // cleared (no other flags)
        ->and($loser->refresh()->arrangement_source)->toBe(ArrangementSource::Confirmed); // the merged section is locked too
});

test('dismissing a dedup flag re-homes onto the runner-up', function () {
    $site = Site::factory()->create();
    $bp = SiloBlueprint::factory()->create(['site_id' => $site->id]);
    $winner = frSpoke($site, $bp, ['silo' => 'A', 'name' => 'Winner', 'flagged' => true]);
    $runnerUp = frSpoke($site, $bp, ['silo' => 'B', 'name' => 'Runner Up', 'granularity' => SpokeGranularity::Folded, 'fold_into_id' => $winner->id]);

    $flag = ArrangementFlag::query()->create([
        'site_id' => $site->id, 'spoke_id' => $winner->id, 'type' => ArrangeFlagType::DedupAmbiguous,
        'message' => 'x', 'candidates' => [], 'alternative' => ['spoke_id' => $runnerUp->id], 'score' => 0.9,
    ]);

    expect(flagResolver($this->fake)->dismiss($site, $flag))->toBeTrue()
        ->and($runnerUp->refresh()->granularity)->toBe(SpokeGranularity::OwnPage)     // runner-up becomes the home
        ->and($runnerUp->fold_into_id)->toBeNull()
        ->and($winner->refresh()->fold_into_id)->toBe($runnerUp->id)                  // winner folds onto it
        ->and($winner->granularity)->toBe(SpokeGranularity::Folded);
});
