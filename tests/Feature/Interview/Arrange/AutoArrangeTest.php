<?php

use App\Enums\ArrangeFlagType;
use App\Enums\ArrangementSource;
use App\Enums\SpokeGranularity;
use App\Enums\SpokePageType;
use App\Enums\SpokeStatus;
use App\Enums\SpokeTag;
use App\Integrations\Embedding\EmbeddingProvider;
use App\Interview\Arrange\AutoArranger;
use App\Interview\Arrange\CrossSiloDedup;
use App\Interview\Arrange\FoldTargetAssigner;
use App\Interview\Arrange\SpokeEmbeddings;
use App\Interview\Arrange\SubClusterDetector;
use App\Models\Scopes\SiteScope;
use App\Models\SiloBlueprint;
use App\Models\Site;
use App\Models\Spoke;

/**
 * Deterministic embeddings for the passes: a spoke's vector is chosen by the first
 * concept substring its name matches, so cosine is engineered, not lexical. Battery
 * concepts are one axis, water another, everything else a shared "generic" axis — this
 * lets a test put two names at cosine 1.0 (near-dup) or 0.0 (unrelated) on purpose.
 */
class ArrangeFakeEmbeddings implements EmbeddingProvider
{
    public function embed(string $text): array
    {
        $t = mb_strtolower($text);

        return match (true) {
            str_contains($t, 'water-powered') => [0.8, 0.6, 0.0], // mostly battery-adjacent, some water
            str_contains($t, 'battery backup') => [1.0, 0.0, 0.0],
            str_contains($t, 'water') => [0.0, 1.0, 0.0],
            default => [0.0, 0.0, 1.0], // generic / pillar axis
        };
    }
}

beforeEach(function () {
    $this->fake = new ArrangeFakeEmbeddings;
});

function arrangeSpoke(Site $site, SiloBlueprint $bp, array $attrs): Spoke
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

function aspk(Site $site, string $name): Spoke
{
    return Spoke::withoutGlobalScope(SiteScope::class)->where('site_id', $site->id)->where('name', $name)->first();
}

test('Pass B folds a cross-silo near-dup into the higher-volume home (floor: never deleted)', function () {
    $site = Site::factory()->create();
    $bp = SiloBlueprint::factory()->create(['site_id' => $site->id]);

    arrangeSpoke($site, $bp, ['silo' => 'Sump Pumps', 'name' => 'Sump Pumps', 'is_pillar' => true]);
    arrangeSpoke($site, $bp, ['silo' => 'Sump Pumps', 'name' => 'Battery Backup', 'volume' => 300]);
    arrangeSpoke($site, $bp, ['silo' => 'Backup Power', 'name' => 'Backup Power', 'is_pillar' => true]);
    arrangeSpoke($site, $bp, ['silo' => 'Backup Power', 'name' => 'Battery Backup System', 'volume' => 20]);

    $result = (new CrossSiloDedup(0.85, 0.15))->run($site, new SpokeEmbeddings($this->fake));

    $loser = aspk($site, 'Battery Backup System');
    $winner = aspk($site, 'Battery Backup');

    expect($result->applied['dedup'])->toBe(1)
        ->and($result->flags)->toBe([])                          // 300 vs 20 — clear gap, silent
        ->and($loser->silo)->toBe('Sump Pumps')                  // re-homed to the winner's silo
        ->and($loser->granularity)->toBe(SpokeGranularity::Folded)
        ->and($loser->fold_into_id)->toBe($winner->id)           // folded onto the home
        ->and($loser->status)->toBe(SpokeStatus::Candidate);     // floor: still a section at finalize, never deleted
});

test('Pass B flags an ambiguous dedup (close volumes) but still applies the pick', function () {
    $site = Site::factory()->create();
    $bp = SiloBlueprint::factory()->create(['site_id' => $site->id]);

    arrangeSpoke($site, $bp, ['silo' => 'Sump Pumps', 'name' => 'Sump Pumps', 'is_pillar' => true]);
    arrangeSpoke($site, $bp, ['silo' => 'Sump Pumps', 'name' => 'Battery Backup', 'volume' => 100]);
    arrangeSpoke($site, $bp, ['silo' => 'Backup Power', 'name' => 'Backup Power', 'is_pillar' => true]);
    arrangeSpoke($site, $bp, ['silo' => 'Backup Power', 'name' => 'Battery Backup System', 'volume' => 95]);

    $result = (new CrossSiloDedup(0.85, 0.15))->run($site, new SpokeEmbeddings($this->fake));

    expect($result->applied['dedup'])->toBe(1)
        ->and($result->flags)->toHaveCount(1)
        ->and($result->flags[0]->type)->toBe(ArrangeFlagType::DedupAmbiguous)
        ->and($result->flags[0]->spokeId)->toBe(aspk($site, 'Battery Backup')->id) // the kept home
        ->and(aspk($site, 'Battery Backup System')->fold_into_id)->toBe(aspk($site, 'Battery Backup')->id);
});

test('Pass B preserves a decided spoke and leaves same-silo siblings alone', function () {
    $site = Site::factory()->create();
    $bp = SiloBlueprint::factory()->create(['site_id' => $site->id]);

    arrangeSpoke($site, $bp, ['silo' => 'Sump Pumps', 'name' => 'Sump Pumps', 'is_pillar' => true]);
    arrangeSpoke($site, $bp, ['silo' => 'Sump Pumps', 'name' => 'Battery Backup', 'volume' => 300]);
    // an operator-confirmed near-dup in another silo — must NOT be moved
    arrangeSpoke($site, $bp, ['silo' => 'Backup Power', 'name' => 'Backup Power', 'is_pillar' => true]);
    arrangeSpoke($site, $bp, ['silo' => 'Backup Power', 'name' => 'Battery Backup System', 'volume' => 20, 'status' => SpokeStatus::Offered]);

    $result = (new CrossSiloDedup(0.85, 0.15))->run($site, new SpokeEmbeddings($this->fake));

    expect($result->applied['dedup'])->toBe(0)
        ->and(aspk($site, 'Battery Backup System')->silo)->toBe('Backup Power'); // preserved
});

test('Pass A nests a folded spoke under its most-related own-page core, not the pillar', function () {
    $site = Site::factory()->create();
    $bp = SiloBlueprint::factory()->create(['site_id' => $site->id]);

    arrangeSpoke($site, $bp, ['silo' => 'Sump Pumps', 'name' => 'Sump Pumps', 'is_pillar' => true]);
    arrangeSpoke($site, $bp, ['silo' => 'Sump Pumps', 'name' => 'Battery Backup', 'volume' => 300]);
    arrangeSpoke($site, $bp, [
        'silo' => 'Sump Pumps', 'name' => 'Water-Powered Backup', 'volume' => 10,
        'tag' => SpokeTag::Adjacent, 'granularity' => SpokeGranularity::Folded, 'fold_into_id' => null,
    ]);

    $result = (new FoldTargetAssigner(0.70))->run($site, new SpokeEmbeddings($this->fake));

    expect($result->applied['nest'])->toBe(1)
        ->and($result->flags)->toBe([])
        ->and(aspk($site, 'Water-Powered Backup')->fold_into_id)->toBe(aspk($site, 'Battery Backup')->id);
});

test('Pass A falls back to the pillar and flags when no core clears the relatedness floor', function () {
    $site = Site::factory()->create();
    $bp = SiloBlueprint::factory()->create(['site_id' => $site->id]);

    $pillar = arrangeSpoke($site, $bp, ['silo' => 'Sump Pumps', 'name' => 'Sump Pumps', 'is_pillar' => true]);
    arrangeSpoke($site, $bp, ['silo' => 'Sump Pumps', 'name' => 'Battery Backup', 'volume' => 300]);
    arrangeSpoke($site, $bp, [
        'silo' => 'Sump Pumps', 'name' => 'Water Alarm', 'volume' => 10,
        'tag' => SpokeTag::Connecting, 'granularity' => SpokeGranularity::Folded, 'fold_into_id' => null,
    ]);

    $result = (new FoldTargetAssigner(0.70))->run($site, new SpokeEmbeddings($this->fake));

    expect($result->flags)->toHaveCount(1)
        ->and($result->flags[0]->type)->toBe(ArrangeFlagType::NestLowConfidence)
        ->and(aspk($site, 'Water Alarm')->fold_into_id)->toBe($pillar->id); // safe fallback
});

test('passes stamp arrangement provenance (source=auto) + the cosine score', function () {
    $site = Site::factory()->create();
    $bp = SiloBlueprint::factory()->create(['site_id' => $site->id]);

    arrangeSpoke($site, $bp, ['silo' => 'Sump Pumps', 'name' => 'Sump Pumps', 'is_pillar' => true]);
    arrangeSpoke($site, $bp, ['silo' => 'Sump Pumps', 'name' => 'Battery Backup', 'volume' => 300]);
    arrangeSpoke($site, $bp, ['silo' => 'Backup Power', 'name' => 'Backup Power', 'is_pillar' => true]);
    arrangeSpoke($site, $bp, ['silo' => 'Backup Power', 'name' => 'Battery Backup System', 'volume' => 20]);

    (new CrossSiloDedup(0.85, 0.15))->run($site, new SpokeEmbeddings($this->fake));

    $loser = aspk($site, 'Battery Backup System');
    expect($loser->arrangement_source)->toBe(ArrangementSource::Auto)
        ->and($loser->arrangement_score)->toBe(1.0); // identical battery vectors
});

test('a confirmed arrangement is preserved — neither pass overwrites it', function () {
    $site = Site::factory()->create();
    $bp = SiloBlueprint::factory()->create(['site_id' => $site->id]);

    arrangeSpoke($site, $bp, ['silo' => 'Sump Pumps', 'name' => 'Sump Pumps', 'is_pillar' => true]);
    arrangeSpoke($site, $bp, ['silo' => 'Sump Pumps', 'name' => 'Battery Backup', 'volume' => 300]);
    arrangeSpoke($site, $bp, ['silo' => 'Backup Power', 'name' => 'Backup Power', 'is_pillar' => true]);
    // operator-confirmed (e.g. dismissed the dedup) but still a routing candidate
    arrangeSpoke($site, $bp, [
        'silo' => 'Backup Power', 'name' => 'Battery Backup System', 'volume' => 20,
        'arrangement_source' => ArrangementSource::Confirmed,
    ]);

    $result = (new AutoArranger($this->fake, new CrossSiloDedup(0.85, 0.15), new SubClusterDetector(0.60), new FoldTargetAssigner(0.70)))->arrange($site);

    expect($result->applied['dedup'])->toBe(0)
        ->and(aspk($site, 'Battery Backup System')->silo)->toBe('Backup Power'); // untouched
});

test('a re-run does not thrash: the second arrange is a no-op', function () {
    $site = Site::factory()->create();
    $bp = SiloBlueprint::factory()->create(['site_id' => $site->id]);

    arrangeSpoke($site, $bp, ['silo' => 'Sump Pumps', 'name' => 'Sump Pumps', 'is_pillar' => true]);
    arrangeSpoke($site, $bp, ['silo' => 'Sump Pumps', 'name' => 'Battery Backup', 'volume' => 300]);
    arrangeSpoke($site, $bp, [
        'silo' => 'Sump Pumps', 'name' => 'Water-Powered Backup', 'volume' => 10,
        'tag' => SpokeTag::Adjacent, 'granularity' => SpokeGranularity::Folded,
    ]);
    arrangeSpoke($site, $bp, ['silo' => 'Backup Power', 'name' => 'Backup Power', 'is_pillar' => true]);
    arrangeSpoke($site, $bp, ['silo' => 'Backup Power', 'name' => 'Battery Backup System', 'volume' => 20]);

    $arranger = new AutoArranger($this->fake, new CrossSiloDedup(0.85, 0.15), new SubClusterDetector(0.60), new FoldTargetAssigner(0.70));
    $arranger->arrange($site);
    $firstTargets = aspk($site, 'Water-Powered Backup')->fold_into_id;

    $second = $arranger->arrange($site);

    expect($second->applied['dedup'])->toBe(0)
        ->and($second->applied['nest'])->toBe(0)
        ->and(aspk($site, 'Water-Powered Backup')->fold_into_id)->toBe($firstTargets);
});

test('AutoArranger runs B then A: dedup merges the pair AND water-powered nests under battery', function () {
    $site = Site::factory()->create();
    $bp = SiloBlueprint::factory()->create(['site_id' => $site->id]);

    arrangeSpoke($site, $bp, ['silo' => 'Sump Pumps', 'name' => 'Sump Pumps', 'is_pillar' => true]);
    arrangeSpoke($site, $bp, ['silo' => 'Sump Pumps', 'name' => 'Battery Backup', 'volume' => 300]);
    arrangeSpoke($site, $bp, [
        'silo' => 'Sump Pumps', 'name' => 'Water-Powered Backup', 'volume' => 10,
        'tag' => SpokeTag::Adjacent, 'granularity' => SpokeGranularity::Folded,
    ]);
    arrangeSpoke($site, $bp, ['silo' => 'Backup Power', 'name' => 'Backup Power', 'is_pillar' => true]);
    arrangeSpoke($site, $bp, ['silo' => 'Backup Power', 'name' => 'Battery Backup System', 'volume' => 20]);

    $result = (new AutoArranger($this->fake, new CrossSiloDedup(0.85, 0.15), new SubClusterDetector(0.60), new FoldTargetAssigner(0.70)))->arrange($site);

    $battery = aspk($site, 'Battery Backup');

    expect($result->applied['dedup'])->toBe(1)
        ->and($result->applied['nest'])->toBeGreaterThanOrEqual(1)
        ->and(aspk($site, 'Battery Backup System')->silo)->toBe('Sump Pumps')
        ->and(aspk($site, 'Battery Backup System')->fold_into_id)->toBe($battery->id)
        ->and(aspk($site, 'Water-Powered Backup')->fold_into_id)->toBe($battery->id);
});
