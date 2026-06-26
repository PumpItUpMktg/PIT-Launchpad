<?php

use App\Build\GuidedEntityProjector;
use App\Build\PageMaterializer;
use App\ContentEngine\Drafting\GroundingReadiness;
use App\Enums\BuildSource;
use App\Enums\BuildStatus;
use App\Enums\SpokeGranularity;
use App\Enums\SpokePageType;
use App\Enums\SpokeStatus;
use App\Enums\SpokeTag;
use App\Models\BuildPage;
use App\Models\CoverageArea;
use App\Models\Market;
use App\Models\Scopes\SiteScope;
use App\Models\Service;
use App\Models\Silo;
use App\Models\SiloBlueprint;
use App\Models\Site;
use App\Models\Spoke;
use Database\Seeders\WireframeKitSeeder;

function projSpoke(Site $site, SiloBlueprint $bp, array $attrs): Spoke
{
    return Spoke::factory()->create(array_merge([
        'site_id' => $site->id,
        'silo_blueprint_id' => $bp->id,
        'status' => SpokeStatus::Offered,
        'tag' => SpokeTag::Core,
        'page_type' => SpokePageType::Service,
        'granularity' => SpokeGranularity::OwnPage,
    ], $attrs));
}

it('projects the spoke structure into §1 Services + §4 Silos, attached', function () {
    $site = Site::factory()->create();
    $bp = SiloBlueprint::factory()->create(['site_id' => $site->id]);
    projSpoke($site, $bp, ['name' => 'Plumbing', 'silo' => 'Plumbing', 'is_pillar' => true]);
    projSpoke($site, $bp, ['name' => 'Water Heater Repair', 'silo' => 'Plumbing', 'is_pillar' => false]);

    app(GuidedEntityProjector::class)->project($site);

    $silos = Silo::withoutGlobalScope(SiteScope::class)->where('site_id', $site->id)->get();
    $services = Service::withoutGlobalScope(SiteScope::class)->where('site_id', $site->id)->get();

    expect($silos->pluck('name'))->toContain('Plumbing')
        ->and($services->pluck('name'))->toContain('Plumbing')->toContain('Water Heater Repair');

    // the service is attached to its silo (so PageGroundingAssembler can silo-scope)
    $service = $services->firstWhere('name', 'Water Heater Repair');
    expect($service->silos()->where('silos.name', 'Plumbing')->exists())->toBeTrue();
});

it('is idempotent — re-projecting does not duplicate', function () {
    $site = Site::factory()->create();
    $bp = SiloBlueprint::factory()->create(['site_id' => $site->id]);
    projSpoke($site, $bp, ['name' => 'Plumbing', 'silo' => 'Plumbing', 'is_pillar' => true]);

    app(GuidedEntityProjector::class)->project($site);
    app(GuidedEntityProjector::class)->project($site);

    expect(Silo::withoutGlobalScope(SiteScope::class)->where('site_id', $site->id)->count())->toBe(1)
        ->and(Service::withoutGlobalScope(SiteScope::class)->where('site_id', $site->id)->count())->toBe(1);
});

it('projects page-selected territories into §1 Markets, idempotently (manual towns are priority)', function () {
    $site = Site::factory()->create();
    CoverageArea::factory()->create(['site_id' => $site->id, 'name' => 'Clifton', 'state' => 'NJ', 'page_selected' => true, 'source' => 'county']);
    CoverageArea::factory()->create(['site_id' => $site->id, 'name' => 'Montclair', 'state' => 'NJ', 'page_selected' => true, 'source' => 'manual']);
    CoverageArea::factory()->create(['site_id' => $site->id, 'name' => 'Newark', 'state' => 'NJ', 'page_selected' => false]); // not selected → no market

    app(GuidedEntityProjector::class)->project($site);
    app(GuidedEntityProjector::class)->project($site); // idempotent

    $markets = Market::withoutGlobalScope(SiteScope::class)->where('site_id', $site->id)->get();

    expect($markets->pluck('name')->sort()->values()->all())->toBe(['Clifton', 'Montclair'])
        ->and($markets->firstWhere('name', 'Montclair')->tier->value)->toBe('priority')   // owner-added → priority
        ->and($markets->firstWhere('name', 'Clifton')->tier->value)->toBe('coverage')
        ->and((bool) $markets->firstWhere('name', 'Clifton')->is_covered)->toBeTrue();
});

it('materializes a location page pinned to its market, so grounding readiness flips to ready', function () {
    $this->seed(WireframeKitSeeder::class);
    $site = Site::factory()->create();
    $town = CoverageArea::factory()->create(['site_id' => $site->id, 'name' => 'Clifton', 'state' => 'NJ', 'page_selected' => true]);

    BuildPage::factory()->create([
        'site_id' => $site->id, 'source' => BuildSource::Location, 'page_key' => $town->id,
        'title' => 'Clifton, NJ', 'recipe' => 'location.town', 'status' => BuildStatus::Queued,
        'priority' => 500, 'review_required' => false, 'spoke_id' => null,
    ]);

    $pages = app(PageMaterializer::class)->materialize($site);
    $page = collect($pages)->firstWhere('title', 'Clifton, NJ');

    expect($page->market_id)->not->toBeNull()
        ->and($page->market->name)->toBe('Clifton')
        ->and(app(GroundingReadiness::class)->ready($page->fresh()))->toBeTrue();
});

it('materializes a service page pinned to its silo, so grounding readiness flips to ready', function () {
    $site = Site::factory()->create();
    $bp = SiloBlueprint::factory()->create(['site_id' => $site->id]);
    $svc = projSpoke($site, $bp, ['name' => 'Water Heater Repair', 'silo' => 'Plumbing', 'is_pillar' => false]);

    BuildPage::factory()->create([
        'site_id' => $site->id, 'source' => BuildSource::Service, 'page_key' => 'whr',
        'title' => 'Water Heater Repair', 'recipe' => 'x', 'status' => BuildStatus::Queued,
        'priority' => 100, 'review_required' => false, 'spoke_id' => $svc->id,
    ]);

    $pages = app(PageMaterializer::class)->materialize($site);
    $page = collect($pages)->firstWhere('title', 'Water Heater Repair');

    expect($page->silo_id)->not->toBeNull()
        ->and(app(GroundingReadiness::class)->ready($page->fresh()))->toBeTrue();
});
