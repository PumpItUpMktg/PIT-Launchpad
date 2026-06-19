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
use App\Interview\Arrange\SubHubDemoter;
use App\Models\Scopes\SiteScope;
use App\Models\SiloBlueprint;
use App\Models\Site;
use App\Models\Spoke;

/**
 * Explicit per-name vectors over [battery, sump, protect, generic]. Sump Pumps is the
 * magnet (canonical battery + sump cores); Backup Power's spokes all point at the battery
 * core and Pump Protection's all point at the sump core — so both sub-cluster into Sump
 * Pumps, but Sump Pumps splits 50/50 and is not itself flagged. All are below the 0.85
 * dedup line, so Pass B leaves them and Pass C sees the full set.
 */
class SubHubFakeEmbeddings implements EmbeddingProvider
{
    public function embed(string $text): array
    {
        return match (true) {
            str_contains($text, 'Battery Backup Sump Pump') => [1.0, 0.0, 0.0, 0.0],
            str_contains($text, 'Sump Pit Basin') => [0.0, 1.0, 0.0, 0.0],
            str_contains($text, 'Battery') => [0.8, 0.0, 0.0, 0.6],          // Backup Power battery spokes
            str_contains($text, 'Sump Pump Monitoring'), str_contains($text, 'Pump Protection Plan') => [0.0, 0.8, 0.0, 0.6],
            default => [0.0, 0.0, 0.0, 1.0],                                  // pillars / generic
        };
    }
}

beforeEach(function () {
    $this->fake = new SubHubFakeEmbeddings;
});

function subSpoke(Site $site, SiloBlueprint $bp, array $attrs): Spoke
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

function sspk(Site $site, string $name): Spoke
{
    return Spoke::withoutGlobalScope(SiteScope::class)->where('site_id', $site->id)->where('name', $name)->first();
}

/** The SPG-shaped fixture: Sump Pumps magnet + Backup Power + Pump Protection sub-clusters. */
function spgSite(Site $site): SiloBlueprint
{
    $bp = SiloBlueprint::factory()->create(['site_id' => $site->id]);

    subSpoke($site, $bp, ['silo' => 'Sump Pumps', 'name' => 'Sump Pumps', 'is_pillar' => true]);
    subSpoke($site, $bp, ['silo' => 'Sump Pumps', 'name' => 'Battery Backup Sump Pump', 'volume' => 300]);
    subSpoke($site, $bp, ['silo' => 'Sump Pumps', 'name' => 'Sump Pit Basin', 'volume' => 150]);

    subSpoke($site, $bp, ['silo' => 'Backup Power', 'name' => 'Backup Power', 'is_pillar' => true]);
    subSpoke($site, $bp, ['silo' => 'Backup Power', 'name' => 'Battery Backup System Installation', 'volume' => 40, 'tag' => SpokeTag::Adjacent, 'granularity' => SpokeGranularity::Folded]);
    subSpoke($site, $bp, ['silo' => 'Backup Power', 'name' => 'Battery Powered Backup', 'volume' => 30, 'tag' => SpokeTag::Adjacent, 'granularity' => SpokeGranularity::Folded]);

    subSpoke($site, $bp, ['silo' => 'Pump Protection', 'name' => 'Pump Protection', 'is_pillar' => true]);
    subSpoke($site, $bp, ['silo' => 'Pump Protection', 'name' => 'Sump Pump Monitoring', 'volume' => 25, 'tag' => SpokeTag::Connecting, 'granularity' => SpokeGranularity::Folded]);
    subSpoke($site, $bp, ['silo' => 'Pump Protection', 'name' => 'Pump Protection Plan', 'volume' => 20, 'tag' => SpokeTag::Connecting, 'granularity' => SpokeGranularity::Folded]);

    return $bp;
}

function demoter(EmbeddingProvider $fake): SubHubDemoter
{
    return new SubHubDemoter($fake, new FoldTargetAssigner(0.70));
}

test('Pass C flags the sub-clusters (Backup Power & Pump Protection) and not the magnet', function () {
    $site = Site::factory()->create();
    spgSite($site);

    $result = (new SubClusterDetector(0.60))->run($site, new SpokeEmbeddings($this->fake));

    $byTarget = collect($result->flags)->mapWithKeys(fn ($f) => [$f->spokeId => $f->candidates[0]['name']]);

    expect($result->flags)->toHaveCount(2)
        ->and(collect($result->flags)->pluck('type')->unique()->all())->toBe([ArrangeFlagType::SubHubDemotion])
        ->and($byTarget->get(sspk($site, 'Backup Power')->id))->toBe('Sump Pumps')
        ->and($byTarget->get(sspk($site, 'Pump Protection')->id))->toBe('Sump Pumps')
        ->and($byTarget->has(sspk($site, 'Sump Pumps')->id))->toBeFalse(); // the magnet itself is not flagged
});

test('demoting re-parents the silo as a sub-hub and nests its battery spokes under the parent core', function () {
    $site = Site::factory()->create();
    spgSite($site);

    expect(demoter($this->fake)->demote($site, 'Backup Power', 'Sump Pumps', ArrangementSource::Confirmed))->toBeTrue();

    $pillar = sspk($site, 'Backup Power');
    expect($pillar->is_sub_hub)->toBeTrue()
        ->and($pillar->parent_silo_id)->toBe(sspk($site, 'Sump Pumps')->id)
        ->and($pillar->arrangement_source)->toBe(ArrangementSource::Confirmed)
        // subtree-aware nesting: a Backup Power spoke now nests under a Sump Pumps core
        ->and(sspk($site, 'Battery Backup System Installation')->fold_into_id)->toBe(sspk($site, 'Battery Backup Sump Pump')->id)
        // stated-service floor: nothing dropped — the spokes are preserved under the sub-hub
        ->and(Spoke::withoutGlobalScope(SiteScope::class)->where('site_id', $site->id)->where('silo', 'Backup Power')->count())->toBe(3);
});

test('a confirmed demotion is not reverted on a re-run', function () {
    $site = Site::factory()->create();
    spgSite($site);
    demoter($this->fake)->demote($site, 'Backup Power', 'Sump Pumps', ArrangementSource::Confirmed);

    $result = (new AutoArranger($this->fake, new CrossSiloDedup(0.85, 0.15), new SubClusterDetector(0.60), new FoldTargetAssigner(0.70), new KeywordAssigner(0.90), new FloorReconciler))->arrange($site);

    $pillar = sspk($site, 'Backup Power');
    expect($pillar->is_sub_hub)->toBeTrue()
        ->and($pillar->parent_silo_id)->toBe(sspk($site, 'Sump Pumps')->id)
        ->and($pillar->arrangement_source)->toBe(ArrangementSource::Confirmed)
        // Pass C must not re-flag an already-placed sub-hub
        ->and(collect($result->flags)->contains(fn ($f) => $f->spokeId === $pillar->id))->toBeFalse();
});

test('an Auto demotion never overwrites a Confirmed one', function () {
    $site = Site::factory()->create();
    spgSite($site);
    demoter($this->fake)->demote($site, 'Backup Power', 'Sump Pumps', ArrangementSource::Confirmed);

    // a later auto pass tries to re-home it elsewhere — refused
    expect(demoter($this->fake)->demote($site, 'Backup Power', 'Pump Protection', ArrangementSource::Auto))->toBeFalse()
        ->and(sspk($site, 'Backup Power')->parent_silo_id)->toBe(sspk($site, 'Sump Pumps')->id);
});

test('the one-level cap holds: cannot demote under a sub-hub, nor demote a parent', function () {
    $site = Site::factory()->create();
    spgSite($site);
    demoter($this->fake)->demote($site, 'Backup Power', 'Sump Pumps', ArrangementSource::Confirmed);

    // Backup Power is now a sub-hub → cannot host Pump Protection
    expect(demoter($this->fake)->demote($site, 'Pump Protection', 'Backup Power'))->toBeFalse()
        ->and(sspk($site, 'Pump Protection')->is_sub_hub)->toBeFalse()
        // Sump Pumps now has a sub-hub child → cannot itself be demoted
        ->and(demoter($this->fake)->demote($site, 'Sump Pumps', 'Pump Protection'))->toBeFalse()
        ->and(sspk($site, 'Sump Pumps')->is_sub_hub)->toBeFalse();
});

test('the cycle + self guards hold', function () {
    $site = Site::factory()->create();
    spgSite($site);
    demoter($this->fake)->demote($site, 'Backup Power', 'Sump Pumps', ArrangementSource::Confirmed);

    expect(demoter($this->fake)->demote($site, 'Sump Pumps', 'Backup Power'))->toBeFalse() // would be a cycle
        ->and(demoter($this->fake)->demote($site, 'Sump Pumps', 'Sump Pumps'))->toBeFalse(); // self
});
