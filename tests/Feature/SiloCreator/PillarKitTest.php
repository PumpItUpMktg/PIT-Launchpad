<?php

use App\Enums\PageType;
use App\Enums\SiloType;
use App\Models\Content;
use App\Models\Scopes\SiteScope;
use App\Models\Site;
use App\Models\WireframeKit;
use App\SiloCreator\ManualSiloCreator;
use Database\Seeders\WireframeKitSeeder;

/**
 * The pillar Content stub a committed silo creates must be GENERATABLE with no
 * tinker — i.e. it must carry a wireframe kit, because PageGroundingAssembler::kit
 * throws when a page has none. The kit is fully determined by page_type, so the
 * factory pins it at birth.
 */
it('pins the page_type-matched library kit on a service pillar so it can generate', function () {
    $this->seed(WireframeKitSeeder::class);
    $site = Site::factory()->create();

    app(ManualSiloCreator::class)->create($site, SiloType::ServicePillar, 'Sump Pump Installation', ['sump pump installation']);

    $pillar = Content::withoutGlobalScope(SiteScope::class)->where('site_id', $site->id)->firstOrFail();
    $serviceKit = WireframeKit::query()->where('page_type', PageType::Service->value)->whereNull('site_id')->firstOrFail();

    expect($pillar->page_type)->toBe(PageType::Service)
        ->and($pillar->wireframe_kit_id)->toBe($serviceKit->id)
        ->and($pillar->wireframe_kit_version)->toBe($serviceKit->version);
});

it('prefers a per-site kit override over the shared library kit', function () {
    $this->seed(WireframeKitSeeder::class);
    $site = Site::factory()->create();
    $override = WireframeKit::query()->create([
        'site_id' => $site->id,
        'page_type' => PageType::Service->value,
        'version' => 2,
        'name' => 'Site service kit',
        'slot_schema' => ['page_type' => 'service', 'version' => 2, 'slots' => []],
    ]);

    app(ManualSiloCreator::class)->create($site, SiloType::ServicePillar, 'Drain Cleaning', ['drain cleaning']);

    $pillar = Content::withoutGlobalScope(SiteScope::class)->where('site_id', $site->id)->firstOrFail();
    expect($pillar->wireframe_kit_id)->toBe($override->id)
        ->and($pillar->wireframe_kit_version)->toBe(2);
});

it('leaves the kit null when no kit exists for the page_type (surfaced at generation)', function () {
    // No kits seeded → nothing to pin; the stub is still created, kit unresolved.
    $site = Site::factory()->create();

    app(ManualSiloCreator::class)->create($site, SiloType::ServicePillar, 'Water Heaters', ['water heater repair']);

    $pillar = Content::withoutGlobalScope(SiteScope::class)->where('site_id', $site->id)->firstOrFail();
    expect($pillar->wireframe_kit_id)->toBeNull();
});
