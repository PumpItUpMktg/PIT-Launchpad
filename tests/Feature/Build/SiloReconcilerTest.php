<?php

use App\Build\GuidedEntityProjector;
use App\Build\SiloReconciler;
use App\Enums\SpokeStatus;
use App\Enums\SpokeTag;
use App\Models\Content;
use App\Models\Keyword;
use App\Models\Scopes\SiteScope;
use App\Models\Silo;
use App\Models\SiloBlueprint;
use App\Models\Site;
use App\Models\Spoke;

function srSilo(Site $site, string $name): Silo
{
    return Silo::withoutGlobalScope(SiteScope::class)->create(['site_id' => $site->id, 'name' => $name, 'type' => 'service_pillar']);
}

function srTreeSilo(Site $site, SiloBlueprint $bp, string $name): void
{
    Spoke::factory()->create([
        'site_id' => $site->id, 'silo_blueprint_id' => $bp->id, 'silo' => $name,
        'name' => $name, 'is_pillar' => true, 'tag' => SpokeTag::Core,
    ]);
}

it('deletes silos not in the current spoke tree, keeps the ones that are', function () {
    $site = Site::factory()->create();
    $bp = SiloBlueprint::factory()->create(['site_id' => $site->id]);
    srTreeSilo($site, $bp, 'Sump Pumps');                 // current tree
    $current = srSilo($site, 'Sump Pumps');               // matches → kept
    $stale = srSilo($site, 'Drainage Solutions');         // not in tree → deleted

    $result = app(SiloReconciler::class)->reconcile($site);

    expect($result)->toMatchArray(['deleted' => 1, 'kept' => 1, 'guarded' => false])
        ->and(Silo::withoutGlobalScope(SiteScope::class)->find($current->id))->not->toBeNull()
        ->and(Silo::withoutGlobalScope(SiteScope::class)->find($stale->id))->toBeNull();
});

it('is GUARDED when the site has no spoke tree — never wipes every silo', function () {
    $site = Site::factory()->create();
    srSilo($site, 'Sump Pumps');
    srSilo($site, 'Drainage Solutions');

    $result = app(SiloReconciler::class)->reconcile($site);

    expect($result)->toMatchArray(['deleted' => 0, 'kept' => 2, 'guarded' => true])
        ->and(Silo::withoutGlobalScope(SiteScope::class)->where('site_id', $site->id)->count())->toBe(2);
});

it('deleting a stale silo nulls its keywords and pages, not deletes them (FK safety)', function () {
    $site = Site::factory()->create();
    $bp = SiloBlueprint::factory()->create(['site_id' => $site->id]);
    srTreeSilo($site, $bp, 'Sump Pumps');
    $stale = srSilo($site, 'Drainage Solutions');
    $keyword = Keyword::withoutGlobalScope(SiteScope::class)->create(['site_id' => $site->id, 'silo_id' => $stale->id, 'query' => 'x', 'source' => 'seed', 'status' => 'candidate']);
    $page = Content::factory()->create(['site_id' => $site->id, 'silo_id' => $stale->id]);

    app(SiloReconciler::class)->reconcile($site);

    expect($keyword->fresh()->silo_id)->toBeNull()   // survives, unassigned
        ->and($page->fresh()->silo_id)->toBeNull()   // page survives, unpinned
        ->and($page->fresh())->not->toBeNull();
});

it('the projector reconciles stale silos at materialize', function () {
    $site = Site::factory()->create();
    $bp = SiloBlueprint::factory()->create(['site_id' => $site->id]);
    Spoke::factory()->create(['site_id' => $site->id, 'silo_blueprint_id' => $bp->id, 'silo' => 'Pumps', 'name' => 'Pumps', 'is_pillar' => true, 'tag' => SpokeTag::Core, 'status' => SpokeStatus::Offered]);
    $stale = srSilo($site, 'Old Silo');

    app(GuidedEntityProjector::class)->project($site);

    expect(Silo::withoutGlobalScope(SiteScope::class)->find($stale->id))->toBeNull()          // stale gone
        ->and(Silo::withoutGlobalScope(SiteScope::class)->where('site_id', $site->id)->where('name', 'Pumps')->exists())->toBeTrue();
});

it('the command dry-runs by default and deletes only with --force', function () {
    $site = Site::factory()->create(['brand_name' => 'SPG']);
    $bp = SiloBlueprint::factory()->create(['site_id' => $site->id]);
    srTreeSilo($site, $bp, 'Sump Pumps');
    srSilo($site, 'Sump Pumps');
    $stale = srSilo($site, 'Water Detection & Leaks');

    $this->artisan('launchpad:reconcile-silos', ['--site' => $site->id])
        ->expectsOutputToContain('Water Detection & Leaks')
        ->expectsOutputToContain('[dry-run]')
        ->assertSuccessful();
    expect(Silo::withoutGlobalScope(SiteScope::class)->find($stale->id))->not->toBeNull();

    $this->artisan('launchpad:reconcile-silos', ['--site' => $site->id, '--force' => true])
        ->expectsOutputToContain('Deleted 1 stale silo')
        ->assertSuccessful();
    expect(Silo::withoutGlobalScope(SiteScope::class)->find($stale->id))->toBeNull();
});
