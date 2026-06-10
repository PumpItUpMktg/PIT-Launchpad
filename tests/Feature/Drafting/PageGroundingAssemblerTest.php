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

it('surfaces a page without a wireframe kit as a failure (the guard wraps it)', function () {
    $site = Site::factory()->create();
    $page = Content::factory()->page()->create(['site_id' => $site->id, 'wireframe_kit_id' => null]);

    expect(fn () => app(PageGroundingAssembler::class)->assemble($page))
        ->toThrow(RuntimeException::class);
});
