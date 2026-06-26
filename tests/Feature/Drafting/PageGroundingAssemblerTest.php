<?php

use App\ContentEngine\Drafting\PageGroundingAssembler;
use App\Enums\PageType;
use App\Enums\ProofType;
use App\Models\Content;
use App\Models\Market;
use App\Models\Offer;
use App\Models\ProofItem;
use App\Models\Service;
use App\Models\ServiceProblem;
use App\Models\Silo;
use App\Models\Site;
use App\Models\SiteBranding;
use App\Models\VoiceProfile;
use App\Models\WireframeKit;
use Database\Seeders\WireframeKitSeeder;

function pageWithIntake(): Content
{
    $site = Site::factory()->create(['brand_name' => 'Lone Star Plumbing']);
    VoiceProfile::factory()->active()->create(['site_id' => $site->id, 'version' => 2]);
    SiteBranding::factory()->create(['site_id' => $site->id]);

    $silo = Silo::factory()->create(['site_id' => $site->id]);
    $service = Service::factory()->create(['site_id' => $site->id, 'name' => 'Tankless Water Heater Installation']);
    ServiceProblem::factory()->create(['service_id' => $service->id, 'phrase' => 'no hot water', 'intent' => 'repair']);
    $silo->services()->attach($service->id);

    Offer::factory()->create(['site_id' => $site->id, 'name' => 'Free install estimate']);
    Market::factory()->create(['site_id' => $site->id, 'name' => 'Austin', 'region' => 'TX']);
    ProofItem::factory()->create([
        'site_id' => $site->id,
        'type' => ProofType::Warranty,
        'payload' => ['label' => '10-year installation warranty'],
        'is_substantiated' => true,
    ]);

    (new WireframeKitSeeder)->run();
    $kit = WireframeKit::where('page_type', 'service')->firstOrFail();

    return Content::factory()->page()->create([
        'site_id' => $site->id,
        'silo_id' => $silo->id,
        'wireframe_kit_id' => $kit->id,
        'page_type' => PageType::Service,
        'slot_payload' => ['hero' => ['heading' => 'placeholder']],
    ]);
}

it('assembles page grounding from the real seeded intake entities', function () {
    $page = pageWithIntake();

    $grounding = app(PageGroundingAssembler::class)->assemble($page);

    expect($grounding->pageType)->toBe(PageType::Service)
        ->and($grounding->kit->slots)->not->toBeEmpty()
        ->and($grounding->voiceProfileVersion)->toBe(2)
        ->and($grounding->services)->toHaveCount(1)
        ->and($grounding->services[0]['name'])->toBe('Tankless Water Heater Installation')
        ->and($grounding->problems)->toHaveCount(1)
        ->and($grounding->problems[0]['phrase'])->toBe('no hot water')
        ->and($grounding->offers[0]['name'])->toBe('Free install estimate')
        ->and($grounding->markets[0]['region'])->toBe('TX')
        ->and($grounding->proof)->toHaveCount(1)
        ->and($grounding->branding['brand_name'])->toBe('Lone Star Plumbing');
});

it('scopes grounding to the page\'s OWN service when one silo holds a cluster of siblings', function () {
    // The toilet bug: replacement / installation / repair share one silo. A page pinned to its own
    // service must ground on THAT service alone — not bleed sibling (or unrelated) copy in.
    $site = Site::factory()->create();
    $silo = Silo::factory()->create(['site_id' => $site->id]);

    $replacement = Service::factory()->create(['site_id' => $site->id, 'name' => 'Toilet Replacement']);
    ServiceProblem::factory()->create(['service_id' => $replacement->id, 'phrase' => 'cracked toilet tank', 'intent' => 'replace']);

    $sewer = Service::factory()->create(['site_id' => $site->id, 'name' => 'Sewer Line Repair']);
    ServiceProblem::factory()->create(['service_id' => $sewer->id, 'phrase' => 'sewer backup', 'intent' => 'repair']);

    $silo->services()->attach([$replacement->id, $sewer->id]); // both in the same silo

    (new WireframeKitSeeder)->run();
    $kit = WireframeKit::where('page_type', 'service')->firstOrFail();

    $page = Content::factory()->page()->create([
        'site_id' => $site->id,
        'silo_id' => $silo->id,
        'primary_service_id' => $replacement->id, // the page is about Toilet Replacement
        'wireframe_kit_id' => $kit->id,
        'page_type' => PageType::Service,
        'slot_payload' => ['hero' => ['heading' => 'placeholder']],
    ]);

    $grounding = app(PageGroundingAssembler::class)->assemble($page);

    expect($grounding->services)->toHaveCount(1)
        ->and($grounding->services[0]['name'])->toBe('Toilet Replacement')
        ->and(collect($grounding->problems)->pluck('phrase'))->toContain('cracked toilet tank')
        ->and(collect($grounding->problems)->pluck('phrase'))->not->toContain('sewer backup');
});

it('falls back to silo-scoped grounding when the page has no pinned service (hub / legacy pages)', function () {
    $site = Site::factory()->create();
    $silo = Silo::factory()->create(['site_id' => $site->id]);
    $a = Service::factory()->create(['site_id' => $site->id, 'name' => 'Drain Cleaning']);
    $b = Service::factory()->create(['site_id' => $site->id, 'name' => 'Hydro Jetting']);
    $silo->services()->attach([$a->id, $b->id]);

    (new WireframeKitSeeder)->run();
    $kit = WireframeKit::where('page_type', 'service')->firstOrFail();

    $page = Content::factory()->page()->create([
        'site_id' => $site->id,
        'silo_id' => $silo->id,
        'primary_service_id' => null, // a hub/category page (or a page predating the pin)
        'wireframe_kit_id' => $kit->id,
        'page_type' => PageType::Hub,
        'slot_payload' => ['hero' => ['heading' => 'placeholder']],
    ]);

    $grounding = app(PageGroundingAssembler::class)->assemble($page);

    expect($grounding->services)->toHaveCount(2); // the whole silo, as before
});

it('foregrounds a location page\'s OWN town as the subject, keeping the rest as service-area context', function () {
    $site = Site::factory()->create();
    Market::factory()->create(['site_id' => $site->id, 'name' => 'Newark', 'region' => 'NJ']);
    $clifton = Market::factory()->create(['site_id' => $site->id, 'name' => 'Clifton', 'region' => 'NJ']);
    Market::factory()->create(['site_id' => $site->id, 'name' => 'Montclair', 'region' => 'NJ']);

    (new WireframeKitSeeder)->run();
    $kit = WireframeKit::where('page_type', 'location')->firstOrFail();

    $page = Content::factory()->page()->create([
        'site_id' => $site->id,
        'market_id' => $clifton->id, // the page is about Clifton
        'wireframe_kit_id' => $kit->id,
        'page_type' => PageType::Location,
        'slot_payload' => ['hero' => 'x'],
    ]);

    $grounding = app(PageGroundingAssembler::class)->assemble($page);

    // the page's own town leads; all three are still present as service-area context
    expect($grounding->markets[0]['name'])->toBe('Clifton')
        ->and(collect($grounding->markets)->pluck('name')->sort()->values()->all())->toBe(['Clifton', 'Montclair', 'Newark']);
});

it('surfaces a page without a wireframe kit as a failure (the guard wraps it)', function () {
    $site = Site::factory()->create();
    $page = Content::factory()->page()->create(['site_id' => $site->id, 'wireframe_kit_id' => null]);

    expect(fn () => app(PageGroundingAssembler::class)->assemble($page))
        ->toThrow(RuntimeException::class);
});
