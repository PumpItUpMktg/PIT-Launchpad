<?php

use App\ContentEngine\Drafting\DraftRequest;
use App\Enums\BeatabilityLane;
use App\Enums\ContentKind;
use App\Enums\DraftTrigger;
use App\Enums\IntakeType;
use App\KeywordGenerator\Gap\GapBrief;
use App\Models\WireframeKit;
use Database\Seeders\WireframeKitSeeder;
use Tests\Support\Draft;
use Tests\Support\DraftingHarness;
use Tests\Support\FakeClaudeClient;

function serviceKit(): WireframeKit
{
    (new WireframeKitSeeder)->run();

    /** @var WireframeKit $kit */
    $kit = WireframeKit::where('page_type', 'service')->firstOrFail();

    return $kit;
}

test('a page draft fills kit slots, not a post body', function () {
    ['site' => $site, 'claim' => $claim] = DraftingHarness::fixture();
    $kit = serviceKit();

    $claude = new FakeClaudeClient(Draft::json([
        'slots' => [
            'hero_problem' => 'No hot water when you need it most?',
            'hero_solution' => 'Same-day tankless installation that never runs cold.',
            'service_features' => ['Endless hot water', 'Lower bills', 'Compact footprint'],
            'why_us' => 'We back every install with a 10-year warranty.',
        ],
        'images' => [[
            'slot' => 'hero_image',
            'prompt' => 'Technician installing a tankless heater',
            'seo_filename' => 'tankless-install.jpg',
            'alt' => 'Technician installing a tankless water heater',
        ]],
        'claims_used' => [['text' => '10-year warranty', 'claim_id' => $claim->id]],
    ]));

    $request = new DraftRequest(
        siteId: $site->id,
        kind: ContentKind::Page,
        intakeType: IntakeType::Directed,
        trigger: DraftTrigger::Gap,
        wireframeKitId: $kit->id,
        pageType: 'service',
        title: 'Tankless Water Heater Installation',
    );

    $content = DraftingHarness::engine($claude)->run($request)->content;

    expect($content->kind)->toBe(ContentKind::Page)
        ->and($content->body)->toBeNull()
        ->and($content->slot_payload)->not->toBeNull()
        ->and($content->slot_payload['hero_problem'])->toContain('hot water')
        ->and($content->slot_payload['service_features'])->toHaveCount(3)
        ->and($content->wireframe_kit_id)->toBe($kit->id)
        ->and($content->page_type->value)->toBe('service')
        ->and($content->meta['image_specs'][0]['slot'])->toBe('hero_image');
});

test('the kit-slot definitions are surfaced to the drafter prompt', function () {
    ['site' => $site, 'claim' => $claim] = DraftingHarness::fixture();
    $kit = serviceKit();

    $claude = new FakeClaudeClient(Draft::json([
        'slots' => ['hero_problem' => 'x'],
        'claims_used' => [['text' => 'w', 'claim_id' => $claim->id]],
    ]));

    DraftingHarness::engine($claude)->run(new DraftRequest(
        siteId: $site->id,
        kind: ContentKind::Page,
        intakeType: IntakeType::Directed,
        trigger: DraftTrigger::Gap,
        wireframeKitId: $kit->id,
        pageType: 'service',
        title: 'Tankless',
    ));

    $prompt = $claude->prompts[0];
    expect($prompt)->toContain('KIT SLOTS')
        ->and($prompt)->toContain('hero_problem')
        // grounded proof slot is tagged as claims-only
        ->and($prompt)->toContain('GROUNDED')
        // image slots are spec-only, never rendered here
        ->and($prompt)->toContain('do NOT render');
});

test('forGap maps a §5 gap-brief into a directed page request', function () {
    $brief = new GapBrief(
        targetKeyword: 'tankless water heater repair',
        altKeywords: ['tankless repair'],
        opportunity: 0.8,
        beatability: 0.6,
        lane: BeatabilityLane::Organic,
        intent: 'commercial',
        siloId: '01HZSILO',
        siloName: 'Water Heaters',
        pageType: 'service',
        kit: 'service-page',
        problemFraming: ['no hot water'],
        coverageRequirements: ['symptoms', 'cost'],
        proofHooks: ['warranty'],
        internalLinks: ['pillar_content_id' => null, 'sibling_silo_ids' => []],
        differentiationAngle: 'same-day local service',
        ctaIntent: 'book a repair',
        priorityLane: 'quick_win',
        seoTargets: ['title' => 'Tankless Repair'],
        quickWin: 0.7,
    );

    $request = DraftRequest::forGap($brief, 'site-123', 'kit-abc', 'kw-9');

    expect($request->kind)->toBe(ContentKind::Page)
        ->and($request->intakeType)->toBe(IntakeType::Directed)
        ->and($request->siloId)->toBe('01HZSILO')
        ->and($request->wireframeKitId)->toBe('kit-abc')
        ->and($request->targetKeywordId)->toBe('kw-9')
        ->and($request->allowsLocalInjection())->toBeFalse();
});
