<?php

use App\Build\BuildManifestAssembler;
use App\Build\PageMaterializer;
use App\Build\ServiceStructureWriter;
use App\Enums\ContentKind;
use App\Enums\PageType;
use App\Enums\ServicePageTreatment;
use App\Enums\SpokeGranularity;
use App\Enums\SpokeStatus;
use App\Models\Content;
use App\Models\Scopes\SiteScope;
use App\Models\Service;
use App\Models\Site;
use App\Models\Spoke;

function sgsvc(Site $site, string $name, array $extra = []): Service
{
    return Service::withoutGlobalScope(SiteScope::class)->create(array_merge([
        'site_id' => $site->id, 'name' => $name,
    ], $extra));
}

// ---- Service model helpers -------------------------------------------------

it('Service::isHub is true only when a service has a page-child, not for section-only or childless', function () {
    $site = Site::factory()->create();

    $hub = sgsvc($site, 'Basement Waterproofing');
    sgsvc($site, 'Sump Pump', ['parent_service_id' => $hub->id, 'page_treatment' => ServicePageTreatment::Page]);

    $sectioned = sgsvc($site, 'Crawl Space');
    sgsvc($site, 'Vapor Barrier', ['parent_service_id' => $sectioned->id, 'page_treatment' => ServicePageTreatment::Section]);

    $lonely = sgsvc($site, 'Radon Mitigation');

    expect($hub->isHub())->toBeTrue()
        ->and($sectioned->isHub())->toBeFalse()
        ->and($lonely->isHub())->toBeFalse();
});

it('Service::canHaveChildren enforces the 2-level cap', function () {
    $site = Site::factory()->create();
    $parent = sgsvc($site, 'Basement Waterproofing');
    $child = sgsvc($site, 'Sump Pump', ['parent_service_id' => $parent->id, 'page_treatment' => ServicePageTreatment::Page]);

    expect($parent->canHaveChildren())->toBeTrue()
        ->and($child->canHaveChildren())->toBeFalse();
});

// ---- ServiceStructureWriter ------------------------------------------------

it('writes a pillar + own_page page-child + folded section-child from the authored tree', function () {
    $site = Site::factory()->create();
    $hub = sgsvc($site, 'Basement Waterproofing');
    sgsvc($site, 'Sump Pump', ['parent_service_id' => $hub->id, 'page_treatment' => ServicePageTreatment::Page]);
    sgsvc($site, 'Vapor Barrier', ['parent_service_id' => $hub->id, 'page_treatment' => ServicePageTreatment::Section]);

    app(ServiceStructureWriter::class)->write($site);

    $spokes = Spoke::withoutGlobalScope(SiteScope::class)->where('site_id', $site->id)->get()->keyBy('name');

    expect($spokes)->toHaveCount(3);
    expect($spokes['Basement Waterproofing']->is_pillar)->toBeTrue()
        ->and($spokes['Basement Waterproofing']->granularity)->toBe(SpokeGranularity::OwnPage)
        ->and($spokes['Basement Waterproofing']->status)->toBe(SpokeStatus::Offered)
        ->and($spokes['Sump Pump']->is_pillar)->toBeFalse()
        ->and($spokes['Sump Pump']->granularity)->toBe(SpokeGranularity::OwnPage)
        ->and($spokes['Sump Pump']->silo)->toBe('Basement Waterproofing')
        ->and($spokes['Vapor Barrier']->granularity)->toBe(SpokeGranularity::Folded);
});

it('is idempotent — a second write replaces, never duplicates, the spoke set', function () {
    $site = Site::factory()->create();
    $hub = sgsvc($site, 'Basement Waterproofing');
    sgsvc($site, 'Sump Pump', ['parent_service_id' => $hub->id, 'page_treatment' => ServicePageTreatment::Page]);

    app(ServiceStructureWriter::class)->write($site);
    app(ServiceStructureWriter::class)->write($site);

    expect(Spoke::withoutGlobalScope(SiteScope::class)->where('site_id', $site->id)->count())->toBe(2);
});

// ---- The decouple: page_type from grouping, not is_pillar ------------------

it('materializes a service-with-a-page-child as a HUB, a section-only/childless service as a SERVICE page', function () {
    $site = Site::factory()->create();

    // Hub: has an own-page child.
    $hub = sgsvc($site, 'Basement Waterproofing');
    sgsvc($site, 'Sump Pump', ['parent_service_id' => $hub->id, 'page_treatment' => ServicePageTreatment::Page]);
    // Standalone: only a section child → renders as a service page, not a thin hub.
    $standalone = sgsvc($site, 'Crawl Space');
    sgsvc($site, 'Vapor Barrier', ['parent_service_id' => $standalone->id, 'page_treatment' => ServicePageTreatment::Section]);

    app(ServiceStructureWriter::class)->write($site);
    app(BuildManifestAssembler::class)->assemble($site);
    app(PageMaterializer::class)->materialize($site);

    $pages = Content::withoutGlobalScope(SiteScope::class)
        ->where('site_id', $site->id)->where('kind', ContentKind::Page->value)->get()->keyBy('title');

    expect($pages['Basement Waterproofing']->page_type)->toBe(PageType::Hub)      // has a page-child
        ->and($pages['Sump Pump']->page_type)->toBe(PageType::Service)             // the spoke
        ->and($pages['Crawl Space']->page_type)->toBe(PageType::Service)           // section-only ⇒ service, not hub
        ->and($pages->has('Vapor Barrier'))->toBeFalse();                          // folded section ⇒ no page
});
