<?php

use App\Enums\SpokeGranularity;
use App\Enums\SpokePageType;
use App\Enums\SpokeStatus;
use App\Enums\SpokeTag;
use App\Integrations\Embedding\EmbeddingProvider;
use App\Models\Scopes\SiteScope;
use App\Models\SiloBlueprint;
use App\Models\Site;
use App\Models\Spoke;

/** Concept-axis keyword vectors, local to this file so the command makes no network call. */
class CmdFakeEmbeddings implements EmbeddingProvider
{
    public function embed(string $text): array
    {
        $t = mb_strtolower($text);

        return match (true) {
            str_contains($t, 'battery backup') => [1.0, 0.0, 0.0],
            str_contains($t, 'sump pit') => [0.0, 1.0, 0.0],
            default => [0.0, 0.0, 1.0],
        };
    }
}

beforeEach(function () {
    app()->instance(EmbeddingProvider::class, new CmdFakeEmbeddings);
});

function cmdSpoke(Site $site, SiloBlueprint $bp, array $attrs): Spoke
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

test('the dry-run prints the proposed tree + summary and writes nothing', function () {
    $site = Site::factory()->create();
    $bp = SiloBlueprint::factory()->create(['site_id' => $site->id]);

    cmdSpoke($site, $bp, ['silo' => 'Sump Pumps', 'name' => 'Sump Pumps', 'is_pillar' => true]);
    cmdSpoke($site, $bp, ['silo' => 'Sump Pumps', 'name' => 'Battery Backup Sump Pump', 'head_keyword' => 'battery backup', 'volume' => 300]);
    cmdSpoke($site, $bp, [
        'silo' => 'Sump Pumps', 'name' => 'Sump Pit Liner', 'head_keyword' => 'sump pit', 'volume' => 10,
        'tag' => SpokeTag::Adjacent, 'granularity' => SpokeGranularity::Folded,
    ]);

    $this->artisan('launchpad:auto-arrange', ['site' => $site->id, '--dry-run' => true])
        ->assertSuccessful()
        ->expectsOutputToContain('Proposed structure')
        ->expectsOutputToContain('Summary')
        ->expectsOutputToContain('Dry run — nothing was written.');

    // Read-only: the passes ran in a rolled-back transaction, so nothing persisted.
    $core = Spoke::withoutGlobalScope(SiteScope::class)->where('site_id', $site->id)->where('name', 'Battery Backup Sump Pump')->first();
    expect($core->primary_keyword)->toBeNull()
        ->and($core->keyword_source)->toBeNull();
});

test('the command refuses to write without --dry-run (the write path is increment 4)', function () {
    $site = Site::factory()->create();

    $this->artisan('launchpad:auto-arrange', ['site' => $site->id])
        ->assertFailed()
        ->expectsOutputToContain('increment 4');
});

test('an unknown site fails cleanly', function () {
    $this->artisan('launchpad:auto-arrange', ['site' => 'nope', '--dry-run' => true])
        ->assertFailed()
        ->expectsOutputToContain('Site not found.');
});
