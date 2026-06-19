<?php

use App\Enums\ArrangeFlagType;
use App\Enums\ArrangementSource;
use App\Enums\SpokeGranularity;
use App\Enums\SpokePageType;
use App\Enums\SpokeStatus;
use App\Enums\SpokeTag;
use App\Integrations\Embedding\EmbeddingProvider;
use App\Interview\Arrange\FoldTargetAssigner;
use App\Interview\Arrange\KeywordAssigner;
use App\Interview\Arrange\SpokeEmbeddings;
use App\Interview\Arrange\SubHubDemoter;
use App\Models\Scopes\SiteScope;
use App\Models\SiloBlueprint;
use App\Models\Site;
use App\Models\Spoke;

/**
 * Keyword-text vectors over orthogonal concept axes — collisions are engineered. Order
 * matters: "battery backup" and "sump pit" are checked before the generic "sump pump"
 * pillar term so a battery core never reads as a pump term.
 */
class KeywordFakeEmbeddings implements EmbeddingProvider
{
    public function embed(string $text): array
    {
        $t = mb_strtolower($text);

        return match (true) {
            str_contains($t, 'battery backup') => [1.0, 0.0, 0.0, 0.0, 0.0],
            str_contains($t, 'sump pit') => [0.0, 1.0, 0.0, 0.0, 0.0],
            str_contains($t, 'backup power') => [0.0, 0.0, 1.0, 0.0, 0.0],
            str_contains($t, 'pump protection') => [0.0, 0.0, 0.0, 1.0, 0.0],
            str_contains($t, 'sump pump'), str_contains($t, 'pump') => [0.0, 0.0, 0.0, 0.0, 1.0],
            default => [0.0, 0.0, 0.0, 0.0, 1.0],
        };
    }
}

beforeEach(function () {
    $this->fake = new KeywordFakeEmbeddings;
    $this->vectors = new SpokeEmbeddings($this->fake);
});

function kwSpoke(Site $site, SiloBlueprint $bp, array $attrs): Spoke
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

function kspk(Site $site, string $name): Spoke
{
    return Spoke::withoutGlobalScope(SiteScope::class)->where('site_id', $site->id)->where('name', $name)->first();
}

test('Pass D gives each page a distinct keyword', function () {
    $site = Site::factory()->create();
    $bp = SiloBlueprint::factory()->create(['site_id' => $site->id]);

    kwSpoke($site, $bp, ['silo' => 'Sump Pumps', 'name' => 'Sump Pumps', 'is_pillar' => true]);
    kwSpoke($site, $bp, ['silo' => 'Sump Pumps', 'name' => 'Battery Backup Sump Pump', 'head_keyword' => 'battery backup', 'volume' => 300]);
    kwSpoke($site, $bp, ['silo' => 'Sump Pumps', 'name' => 'Sump Pit Basin', 'head_keyword' => 'sump pit', 'volume' => 150]);

    $result = (new KeywordAssigner(0.90))->run($site, $this->vectors);

    expect($result->flags)->toBe([])
        ->and(kspk($site, 'Battery Backup Sump Pump')->primary_keyword)->toBe('battery backup')
        ->and(kspk($site, 'Battery Backup Sump Pump')->keyword_source)->toBe(ArrangementSource::Auto)
        ->and(kspk($site, 'Sump Pit Basin')->primary_keyword)->toBe('sump pit')
        ->and(kspk($site, 'Sump Pumps')->primary_keyword)->toBe('Sump Pumps'); // pillar category head (no hk → silo)
});

test('two sibling own-page cores that collide fold the lower-priority one into the winner', function () {
    $site = Site::factory()->create();
    $bp = SiloBlueprint::factory()->create(['site_id' => $site->id]);

    kwSpoke($site, $bp, ['silo' => 'Sump Pumps', 'name' => 'Sump Pumps', 'is_pillar' => true]);
    kwSpoke($site, $bp, ['silo' => 'Sump Pumps', 'name' => 'Battery Backup Pro', 'head_keyword' => 'battery backup', 'volume' => 300]);
    kwSpoke($site, $bp, ['silo' => 'Sump Pumps', 'name' => 'Battery Backup Lite', 'head_keyword' => 'battery backup', 'volume' => 50]);

    $result = (new KeywordAssigner(0.90))->run($site, $this->vectors);

    $winner = kspk($site, 'Battery Backup Pro');
    $loser = kspk($site, 'Battery Backup Lite');

    expect($result->applied['keyword_fold'])->toBe(1)
        ->and($winner->primary_keyword)->toBe('battery backup')
        ->and($loser->granularity)->toBe(SpokeGranularity::Folded)
        ->and($loser->fold_into_id)->toBe($winner->id)
        ->and($loser->primary_keyword)->toBeNull(); // a section, not a page
});

test('a sub-hub lands on its umbrella term, distinct from a child', function () {
    $site = Site::factory()->create();
    $bp = SiloBlueprint::factory()->create(['site_id' => $site->id]);

    kwSpoke($site, $bp, ['silo' => 'Sump Pumps', 'name' => 'Sump Pumps', 'is_pillar' => true]);
    kwSpoke($site, $bp, ['silo' => 'Sump Pumps', 'name' => 'Battery Backup Sump Pump', 'head_keyword' => 'battery backup', 'volume' => 300]);
    kwSpoke($site, $bp, ['silo' => 'Backup Power', 'name' => 'Backup Power', 'is_pillar' => true]);
    kwSpoke($site, $bp, ['silo' => 'Backup Power', 'name' => 'Battery Backup System', 'head_keyword' => 'battery backup', 'volume' => 40, 'granularity' => SpokeGranularity::Folded]);

    (new SubHubDemoter($this->fake, new FoldTargetAssigner(0.70)))->demote($site, 'Backup Power', 'Sump Pumps', ArrangementSource::Confirmed, $this->vectors);

    $result = (new KeywordAssigner(0.90))->run($site, $this->vectors);

    $subHub = kspk($site, 'Backup Power');
    expect($subHub->isSubHub())->toBeTrue()
        ->and($subHub->primary_keyword)->toBe('Backup Power')             // umbrella, NOT "battery backup"
        ->and($subHub->keyword_source)->toBe(ArrangementSource::Auto)     // confirmed demotion didn't freeze the keyword
        ->and(collect($result->flags)->where('type', ArrangeFlagType::SubHubKeywordCollision))->toHaveCount(0);
});

test('a sub-hub whose umbrella still collides with a child is flagged, never collapsed', function () {
    $site = Site::factory()->create();
    $bp = SiloBlueprint::factory()->create(['site_id' => $site->id]);

    kwSpoke($site, $bp, ['silo' => 'Sump Pumps', 'name' => 'Sump Pumps', 'is_pillar' => true]);
    kwSpoke($site, $bp, ['silo' => 'Sump Pumps', 'name' => 'Battery Backup Sump Pump', 'head_keyword' => 'battery backup', 'volume' => 300]);
    // the silo name itself reads as the child term — no distinct umbrella exists
    kwSpoke($site, $bp, ['silo' => 'Battery Backup', 'name' => 'Battery Backup', 'is_pillar' => true]);
    kwSpoke($site, $bp, ['silo' => 'Battery Backup', 'name' => 'Battery Backup Unit', 'head_keyword' => 'battery backup', 'volume' => 30, 'granularity' => SpokeGranularity::Folded]);

    (new SubHubDemoter($this->fake, new FoldTargetAssigner(0.70)))->demote($site, 'Battery Backup', 'Sump Pumps', ArrangementSource::Confirmed, $this->vectors);

    $result = (new KeywordAssigner(0.90))->run($site, $this->vectors);

    $flag = collect($result->flags)->firstWhere('type', ArrangeFlagType::SubHubKeywordCollision);
    expect($flag)->not->toBeNull()
        ->and($flag->spokeId)->toBe(kspk($site, 'Battery Backup')->id);
});

test('a confirmed keyword survives a re-run (auto writes only over auto)', function () {
    $site = Site::factory()->create();
    $bp = SiloBlueprint::factory()->create(['site_id' => $site->id]);

    kwSpoke($site, $bp, ['silo' => 'Sump Pumps', 'name' => 'Sump Pumps', 'is_pillar' => true]);
    kwSpoke($site, $bp, [
        'silo' => 'Sump Pumps', 'name' => 'Battery Backup Sump Pump', 'head_keyword' => 'battery backup', 'volume' => 300,
        'primary_keyword' => 'operator picked', 'keyword_source' => ArrangementSource::Confirmed,
    ]);

    (new KeywordAssigner(0.90))->run($site, $this->vectors);

    expect(kspk($site, 'Battery Backup Sump Pump')->primary_keyword)->toBe('operator picked');
});

test('two pillars that collide raise a KeywordCollision flag', function () {
    $site = Site::factory()->create();
    $bp = SiloBlueprint::factory()->create(['site_id' => $site->id]);

    kwSpoke($site, $bp, ['silo' => 'Alpha', 'name' => 'Alpha', 'is_pillar' => true, 'head_keyword' => 'battery backup']);
    kwSpoke($site, $bp, ['silo' => 'Bravo', 'name' => 'Bravo', 'is_pillar' => true, 'head_keyword' => 'battery backup']);

    $result = (new KeywordAssigner(0.90))->run($site, $this->vectors);

    expect(collect($result->flags)->where('type', ArrangeFlagType::KeywordCollision))->toHaveCount(1);
});
