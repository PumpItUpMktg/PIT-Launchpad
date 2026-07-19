<?php

use App\Build\StructureResetter;
use App\Enums\BlogTargetStatus;
use App\Models\BlogTarget;
use App\Models\Keyword;
use App\Models\Scopes\SiteScope;
use App\Models\Service;
use App\Models\Silo;
use App\Models\SiloBlueprint;
use App\Models\Site;
use App\Models\Spoke;

function rsBlueprint(Site $site): SiloBlueprint
{
    $bp = SiloBlueprint::factory()->create(['site_id' => $site->id, 'trade' => 'plumbing']);
    $bp->forceFill(['confirmed_at' => now(), 'prune_draft' => ['x' => 1]])->save();

    return $bp;
}

function rsQueuedTarget(Site $site, SiloBlueprint $bp): BlogTarget
{
    $silo = Silo::withoutGlobalScope(SiteScope::class)->create(['site_id' => $site->id, 'name' => 'Pumps', 'type' => 'service_pillar']);
    $keyword = Keyword::withoutGlobalScope(SiteScope::class)->create(['site_id' => $site->id, 'query' => 'sump pump repair', 'source' => 'seed', 'status' => 'candidate']);

    return BlogTarget::withoutGlobalScope(SiteScope::class)->create([
        'site_id' => $site->id, 'silo_id' => $silo->id, 'keyword_id' => $keyword->id,
        'status' => BlogTargetStatus::Queued, 'queued_at' => now(),
    ]);
}

it('previews the generated structure without writing', function () {
    $site = Site::factory()->create();
    $bp = rsBlueprint($site);
    Spoke::factory()->count(3)->create(['site_id' => $site->id, 'silo_blueprint_id' => $bp->id]);
    rsQueuedTarget($site, $bp);

    $preview = app(StructureResetter::class)->preview($site);

    expect($preview)->toBe(['spokes' => 3, 'queued_targets' => 1, 'blueprint' => true])
        ->and(Spoke::withoutGlobalScope(SiteScope::class)->where('site_id', $site->id)->count())->toBe(3); // untouched
});

it('clears spokes + queued targets and resets blueprint state — keeping the seed', function () {
    $site = Site::factory()->create();
    $bp = rsBlueprint($site);
    Spoke::factory()->count(5)->create(['site_id' => $site->id, 'silo_blueprint_id' => $bp->id]);
    rsQueuedTarget($site, $bp);
    $service = Service::withoutGlobalScope(SiteScope::class)->create(['site_id' => $site->id, 'name' => 'Sump Pump Repair']);

    app(StructureResetter::class)->reset($site);

    // The generated layer is gone…
    expect(Spoke::withoutGlobalScope(SiteScope::class)->where('site_id', $site->id)->count())->toBe(0)
        ->and(BlogTarget::withoutGlobalScope(SiteScope::class)->where('site_id', $site->id)->count())->toBe(0);

    // …the blueprint stays but its generated-state is reset while the seed (trade) survives…
    $fresh = $bp->fresh();
    expect($fresh)->not->toBeNull()
        ->and($fresh->confirmed_at)->toBeNull()
        ->and($fresh->prune_draft)->toBeNull()
        ->and($fresh->trade)->toBe('plumbing');

    // …and the clean stated catalog is never touched.
    expect($service->fresh())->not->toBeNull();
});

it('keeps consumed (drafted/published) blog targets — only queued rows are orphaned', function () {
    $site = Site::factory()->create();
    $bp = rsBlueprint($site);
    $silo = Silo::withoutGlobalScope(SiteScope::class)->create(['site_id' => $site->id, 'name' => 'Pumps', 'type' => 'service_pillar']);
    $kw1 = Keyword::withoutGlobalScope(SiteScope::class)->create(['site_id' => $site->id, 'query' => 'a', 'source' => 'seed', 'status' => 'candidate']);
    $kw2 = Keyword::withoutGlobalScope(SiteScope::class)->create(['site_id' => $site->id, 'query' => 'b', 'source' => 'seed', 'status' => 'candidate']);
    BlogTarget::withoutGlobalScope(SiteScope::class)->create(['site_id' => $site->id, 'silo_id' => $silo->id, 'keyword_id' => $kw1->id, 'status' => BlogTargetStatus::Queued, 'queued_at' => now()]);
    $drafted = BlogTarget::withoutGlobalScope(SiteScope::class)->create(['site_id' => $site->id, 'silo_id' => $silo->id, 'keyword_id' => $kw2->id, 'status' => BlogTargetStatus::Drafted, 'queued_at' => now()]);

    app(StructureResetter::class)->reset($site);

    $rows = BlogTarget::withoutGlobalScope(SiteScope::class)->where('site_id', $site->id)->get();
    expect($rows->pluck('id')->all())->toBe([$drafted->id]); // history survives, queued cleared
});

it('the command dry-runs by default and clears only with --force', function () {
    $site = Site::factory()->create(['brand_name' => 'SPG']);
    $bp = rsBlueprint($site);
    Spoke::factory()->count(4)->create(['site_id' => $site->id, 'silo_blueprint_id' => $bp->id]);

    // Dry-run: reports, deletes nothing.
    $this->artisan('launchpad:reset-structure', ['--site' => $site->id])
        ->expectsOutputToContain('would clear 4 spoke(s)')
        ->expectsOutputToContain('[dry-run]')
        ->assertSuccessful();
    expect(Spoke::withoutGlobalScope(SiteScope::class)->where('site_id', $site->id)->count())->toBe(4);

    // --force: clears.
    $this->artisan('launchpad:reset-structure', ['--site' => $site->id, '--force' => true])
        ->expectsOutputToContain('cleared 4 spoke(s)')
        ->assertSuccessful();
    expect(Spoke::withoutGlobalScope(SiteScope::class)->where('site_id', $site->id)->count())->toBe(0);
});
