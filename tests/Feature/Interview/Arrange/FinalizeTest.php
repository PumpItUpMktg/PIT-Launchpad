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
use App\Interview\Arrange\FloorReconciler;
use App\Interview\Arrange\FoldTargetAssigner;
use App\Interview\Arrange\KeywordAssigner;
use App\Interview\Arrange\SpokeEmbeddings;
use App\Interview\Arrange\SubClusterDetector;
use App\Models\Scopes\SiteScope;
use App\Models\SiloBlueprint;
use App\Models\Site;
use App\Models\Spoke;

/** Engineered vectors for Pass E / margin / idempotency. */
class FinalizeFakeEmbeddings implements EmbeddingProvider
{
    public function embed(string $text): array
    {
        $t = mb_strtolower($text);

        return match (true) {
            str_contains($t, 'core a') => [0.8, 0.6, 0.0, 0.0],
            str_contains($t, 'core b') => [0.82, 0.5724, 0.0, 0.0],
            str_contains($t, 'core c') => [0.9, 0.4359, 0.0, 0.0],
            str_contains($t, 'battery mini') => [0.9, 0.4359, 0.0, 0.0],
            str_contains($t, 'battery') => [1.0, 0.0, 0.0, 0.0],
            str_contains($t, 'margin spoke'), str_contains($t, 'orphan') => [1.0, 0.0, 0.0, 0.0],
            default => [0.0, 0.0, 0.0, 1.0],
        };
    }
}

beforeEach(function () {
    $this->fake = new FinalizeFakeEmbeddings;
    $this->vectors = new SpokeEmbeddings($this->fake);
});

function finSpoke(Site $site, SiloBlueprint $bp, array $attrs): Spoke
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

function fspk(Site $site, string $name): Spoke
{
    return Spoke::withoutGlobalScope(SiteScope::class)->where('site_id', $site->id)->where('name', $name)->first();
}

test('Pass E re-points a folded spoke off an invalid (folded) target onto the pillar', function () {
    $site = Site::factory()->create();
    $bp = SiloBlueprint::factory()->create(['site_id' => $site->id]);

    $pillar = finSpoke($site, $bp, ['silo' => 'Hub', 'name' => 'Hub', 'is_pillar' => true]);
    // Bad Target is itself folded (already valid: points at the pillar) but a folded page can't host a section.
    $bad = finSpoke($site, $bp, ['silo' => 'Hub', 'name' => 'Bad Target', 'granularity' => SpokeGranularity::Folded, 'fold_into_id' => $pillar->id, 'volume' => 5]);
    finSpoke($site, $bp, ['silo' => 'Hub', 'name' => 'Orphan', 'granularity' => SpokeGranularity::Folded, 'fold_into_id' => $bad->id, 'volume' => 5]);

    $result = (new FloorReconciler)->run($site, $this->vectors);

    expect($result->applied['reconciled'])->toBe(1)
        ->and(fspk($site, 'Orphan')->fold_into_id)->toBe($pillar->id); // a folded page can't host a section
});

test('Pass E fills a null fold target with the pillar (floor)', function () {
    $site = Site::factory()->create();
    $bp = SiloBlueprint::factory()->create(['site_id' => $site->id]);

    $pillar = finSpoke($site, $bp, ['silo' => 'Hub', 'name' => 'Hub', 'is_pillar' => true]);
    finSpoke($site, $bp, ['silo' => 'Hub', 'name' => 'Orphan', 'granularity' => SpokeGranularity::Folded, 'fold_into_id' => null, 'volume' => 5]);

    (new FloorReconciler)->run($site, $this->vectors);

    expect(fspk($site, 'Orphan')->fold_into_id)->toBe($pillar->id);
});

test('Pass E preserves a confirmed spoke (no re-point)', function () {
    $site = Site::factory()->create();
    $bp = SiloBlueprint::factory()->create(['site_id' => $site->id]);

    finSpoke($site, $bp, ['silo' => 'Hub', 'name' => 'Hub', 'is_pillar' => true]);
    finSpoke($site, $bp, [
        'silo' => 'Hub', 'name' => 'Orphan', 'granularity' => SpokeGranularity::Folded,
        'fold_into_id' => null, 'arrangement_source' => ArrangementSource::Confirmed, 'volume' => 5,
    ]);

    $result = (new FloorReconciler)->run($site, $this->vectors);

    expect($result->applied['reconciled'])->toBe(0)
        ->and(fspk($site, 'Orphan')->fold_into_id)->toBeNull();
});

test('Pass E emits a dead-silo advisory flag for a thin silo', function () {
    $site = Site::factory()->create(); // default own-page bar = 100
    $bp = SiloBlueprint::factory()->create(['site_id' => $site->id]);

    finSpoke($site, $bp, ['silo' => 'Thin', 'name' => 'Thin', 'is_pillar' => true]);
    finSpoke($site, $bp, ['silo' => 'Thin', 'name' => 'Thin Service', 'granularity' => SpokeGranularity::Folded, 'volume' => 10]);

    $result = (new FloorReconciler)->run($site, $this->vectors);

    $flag = collect($result->flags)->firstWhere('type', ArrangeFlagType::DeadSilo);
    expect($flag)->not->toBeNull()
        ->and($flag->spokeId)->toBe(fspk($site, 'Thin')->id);
});

test('margin-to-reflip: a sub-margin improvement does NOT move an existing auto fold target', function () {
    $site = Site::factory()->create();
    $bp = SiloBlueprint::factory()->create(['site_id' => $site->id]);

    finSpoke($site, $bp, ['silo' => 'Hub', 'name' => 'Hub', 'is_pillar' => true]);
    $coreA = finSpoke($site, $bp, ['silo' => 'Hub', 'name' => 'Core A', 'volume' => 200]);
    finSpoke($site, $bp, ['silo' => 'Hub', 'name' => 'Core B', 'volume' => 150]);
    finSpoke($site, $bp, [
        'silo' => 'Hub', 'name' => 'Margin Spoke', 'granularity' => SpokeGranularity::Folded, 'volume' => 5,
        'fold_into_id' => $coreA->id, 'arrangement_source' => ArrangementSource::Auto, 'arrangement_score' => 0.80,
    ]);

    (new FoldTargetAssigner(0.70, 0.05))->run($site, $this->vectors); // Core B at 0.82 < 0.80 + 0.05

    expect(fspk($site, 'Margin Spoke')->fold_into_id)->toBe($coreA->id); // held
});

test('margin-to-reflip: a supra-margin improvement DOES move the target', function () {
    $site = Site::factory()->create();
    $bp = SiloBlueprint::factory()->create(['site_id' => $site->id]);

    finSpoke($site, $bp, ['silo' => 'Hub', 'name' => 'Hub', 'is_pillar' => true]);
    $coreA = finSpoke($site, $bp, ['silo' => 'Hub', 'name' => 'Core A', 'volume' => 200]);
    $coreC = finSpoke($site, $bp, ['silo' => 'Hub', 'name' => 'Core C', 'volume' => 150]);
    finSpoke($site, $bp, [
        'silo' => 'Hub', 'name' => 'Margin Spoke', 'granularity' => SpokeGranularity::Folded, 'volume' => 5,
        'fold_into_id' => $coreA->id, 'arrangement_source' => ArrangementSource::Auto, 'arrangement_score' => 0.80,
    ]);

    (new FoldTargetAssigner(0.70, 0.05))->run($site, $this->vectors); // Core C at 0.90 >= 0.80 + 0.05

    expect(fspk($site, 'Margin Spoke')->fold_into_id)->toBe($coreC->id); // moved
});

test('the full B-C-A-D-E pipeline is idempotent: the second run is a no-op', function () {
    $site = Site::factory()->create();
    $bp = SiloBlueprint::factory()->create(['site_id' => $site->id]);

    finSpoke($site, $bp, ['silo' => 'Pumps', 'name' => 'Pumps', 'is_pillar' => true]);
    finSpoke($site, $bp, ['silo' => 'Pumps', 'name' => 'Battery Core', 'head_keyword' => 'battery core', 'volume' => 300]);
    finSpoke($site, $bp, ['silo' => 'Pumps', 'name' => 'Battery Mini', 'head_keyword' => 'battery mini', 'volume' => 10, 'tag' => SpokeTag::Adjacent, 'granularity' => SpokeGranularity::Folded]);
    finSpoke($site, $bp, ['silo' => 'Power', 'name' => 'Power', 'is_pillar' => true]);
    finSpoke($site, $bp, ['silo' => 'Power', 'name' => 'Battery Dup', 'head_keyword' => 'battery dup', 'volume' => 20]);

    $arranger = new AutoArranger($this->fake, new CrossSiloDedup(0.85, 0.15), new SubClusterDetector(0.60), new FoldTargetAssigner(0.70, 0.05), new KeywordAssigner(0.90), new FloorReconciler);
    $arranger->arrange($site);
    $second = $arranger->arrange($site);

    expect($second->applied['dedup'] ?? 0)->toBe(0)
        ->and($second->applied['nest'] ?? 0)->toBe(0)
        ->and($second->applied['keyword_fold'] ?? 0)->toBe(0)
        ->and($second->applied['reconciled'] ?? 0)->toBe(0);
});
