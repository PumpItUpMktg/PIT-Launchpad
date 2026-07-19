<?php

use App\Build\ProjectedServiceCleaner;
use App\Gathering\Provenance;
use App\Models\Scopes\SiteScope;
use App\Models\Service;
use App\Models\SiloBlueprint;
use App\Models\Site;
use App\Models\Spoke;

function svc(Site $site, array $attrs): Service
{
    return Service::withoutGlobalScope(SiteScope::class)->create(array_merge(['site_id' => $site->id], $attrs));
}

function structureSpoke(Site $site, string $name): Spoke
{
    $bp = SiloBlueprint::factory()->create(['site_id' => $site->id]);

    return Spoke::factory()->create(['site_id' => $site->id, 'silo_blueprint_id' => $bp->id, 'name' => $name]);
}

it('flags a projected service — no provenance, no enrichment, name-matches a spoke', function () {
    $site = Site::factory()->create();
    structureSpoke($site, 'Drainage Solutions');
    svc($site, ['name' => 'Drainage Solutions']); // the contamination

    $rows = app(ProjectedServiceCleaner::class)->contaminated($site);

    expect($rows->pluck('name')->all())->toBe(['Drainage Solutions']);
});

it('spares a STATED service that name-matches its spoke (provenance present)', function () {
    $site = Site::factory()->create();
    structureSpoke($site, 'Mold Testing');
    $stated = svc($site, ['name' => 'Mold Testing']);
    app(Provenance::class)->seed($stated, 'name'); // interview-seeded → real

    expect(app(ProjectedServiceCleaner::class)->contaminated($site)->all())->toBe([]);
});

it('spares a MANUAL service that name-matches a spoke (no provenance, but enriched)', function () {
    $site = Site::factory()->create();
    structureSpoke($site, 'Radon Mitigation');
    svc($site, ['name' => 'Radon Mitigation', 'short_description' => 'Sub-slab depressurization systems.']); // operator-worked

    expect(app(ProjectedServiceCleaner::class)->contaminated($site)->all())->toBe([]);
});

it('spares an unenriched, provenance-less service that does NOT match any structure name', function () {
    $site = Site::factory()->create();
    structureSpoke($site, 'Sump Pump Repair');
    svc($site, ['name' => 'Moen Flo Leak Detection']); // genuine stated service, no matching spoke → survives

    expect(app(ProjectedServiceCleaner::class)->contaminated($site)->all())->toBe([]);
});

it('requires BOTH conditions — an enriched match and a no-match junk row both survive', function () {
    $site = Site::factory()->create();
    structureSpoke($site, 'Basement Waterproofing Cost Guide');
    // enriched + matches → survives (either-alone would wrongly kill it)
    svc($site, ['name' => 'Basement Waterproofing Cost Guide', 'description' => 'Real service copy.']);
    // unenriched + no provenance but no match → survives
    svc($site, ['name' => 'Custom One-Off Service']);

    expect(app(ProjectedServiceCleaner::class)->contaminated($site)->all())->toBe([]);
});

it('the command dry-runs by default and deletes only with --force', function () {
    $site = Site::factory()->create();
    structureSpoke($site, 'Why Is My Basement Wet?');
    svc($site, ['name' => 'Why Is My Basement Wet?']);

    // Dry-run: reports, deletes nothing.
    $this->artisan('launchpad:clean-projected-services', ['--site' => $site->id])
        ->expectsOutputToContain('Why Is My Basement Wet?')
        ->expectsOutputToContain('[dry-run]')
        ->assertSuccessful();
    expect(Service::withoutGlobalScope(SiteScope::class)->where('site_id', $site->id)->count())->toBe(1);

    // --force: actually deletes.
    $this->artisan('launchpad:clean-projected-services', ['--site' => $site->id, '--force' => true])
        ->assertSuccessful();
    expect(Service::withoutGlobalScope(SiteScope::class)->where('site_id', $site->id)->count())->toBe(0);
});
