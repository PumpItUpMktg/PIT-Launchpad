<?php

use App\ContentEngine\Drafting\DraftCall;
use App\ContentEngine\Drafting\DraftGuard;
use App\ContentEngine\Drafting\PageDrafter;
use App\ContentEngine\Drafting\PageDraftingEngine;
use App\ContentEngine\Drafting\PageGroundingAssembler;
use App\ContentEngine\Drafting\SlotShaper;
use App\ContentEngine\Generation\PageGenerator;
use App\Enums\ContentStatus;
use App\Enums\SiloType;
use App\Models\Content;
use App\Models\Scopes\SiteScope;
use App\Models\Site;
use App\PageBuilder\Validation\KitValidator;
use App\Publishing\RenderCoordinator;
use App\Publishing\RenderOutcome;
use App\SiloCreator\ManualSiloCreator;
use Database\Seeders\WireframeKitSeeder;
use Illuminate\Support\Collection;
use Tests\Support\FakeClaudeClient;
use Tests\Support\PageFixture;

/**
 * The gate's silent-failure question: a fresh, wizard-born tenant has NO
 * VoiceProfile, NO proof, NO services — does its pillar still draft, or hard-fail
 * with no obvious reason? This builds exactly that bare tenant (only a kitted
 * silo) and proves generation completes: voice falls back to a default, and
 * proof/entity grounding is a publish-time gate, not a draft-time one.
 */
function barePillar(): Content
{
    (new WireframeKitSeeder)->run();
    $site = Site::factory()->create(['brand_name' => 'Bare Co']); // no voice, proof, services, branding, offers, markets

    app(ManualSiloCreator::class)->create($site, SiloType::ServicePillar, 'Sump Pump Installation', ['sump pump installation']);

    return Content::withoutGlobalScope(SiteScope::class)->where('site_id', $site->id)->firstOrFail();
}

it('assembles page grounding with no voice/proof/services — no throw, default voice', function () {
    $pillar = barePillar();

    $grounding = app(PageGroundingAssembler::class)->assemble($pillar);

    expect($grounding->voiceProfileVersion)->toBe(0)   // no active VoiceProfile
        ->and($grounding->voiceProfile)->toBe([])       // → default brand voice downstream
        ->and($grounding->kit->version)->toBe(1);       // the pinned service kit resolved
});

it('drafts a bare tenant pillar end to end — no VoiceProfile/proof/services is NOT a hard fail', function () {
    $pillar = barePillar();
    expect($pillar->hasDraft())->toBeFalse();

    $claude = new FakeClaudeClient(PageFixture::validResponse('no-such-claim')); // bare tenant has no proof to cite
    $engine = new PageDraftingEngine(
        new PageGroundingAssembler,
        new PageDrafter(new DraftCall($claude)),
        new DraftGuard,
        app(KitValidator::class),
        new SlotShaper,
    );

    $renders = Mockery::mock(RenderCoordinator::class);
    $renders->shouldReceive('render')->once()->andReturn(new RenderOutcome(new Collection, true, []));

    $result = (new PageGenerator($engine, $renders))->generate($pillar->fresh());

    expect($result->status)->toBe(ContentStatus::NeedsReview) // drafted, not failed
        ->and($result->hasDraft())->toBeTrue()
        ->and($result->voice_profile_version)->toBe(0);        // default voice, no profile required

    // Structurally complete: every generated slot the kit expects is present and
    // non-empty (KitValidator already enforced this before persist — a structural
    // gap would have surfaced a draft failure, not reached needs_review).
    $slots = $result->slot_payload;
    foreach (['hero_problem', 'hero_solution', 'problem_explainer', 'solution_overview', 'service_features', 'why_us', 'faq'] as $key) {
        expect($slots)->toHaveKey($key)
            ->and($slots[$key])->not->toBeEmpty();
    }

    // Clean omission, not lorem ghosts: the proof-backed entity slots are simply
    // ABSENT from the draft (they resolve downstream from §1 data — a bare tenant
    // has none), never written as empty/placeholder values. The conditional
    // testimonial (has_reviews == true) is omitted by the same mechanism.
    foreach (['testimonial', 'proof_strip', 'cta', 'contact_block', 'hero_image'] as $entitySlot) {
        expect($slots)->not->toHaveKey($entitySlot);
    }

    // No empty string / empty array slipped into any drafted slot.
    foreach ($slots as $value) {
        expect($value)->not->toBeEmpty();
    }
});

/*
 * What this test CANNOT prove: whether the default-voice copy a LIVE model returns
 * reads as publishable vs. generic — the FakeClaudeClient returns fixed content, so
 * content quality of the voice-less prompt is a live-model property. That is exactly
 * what rehearsal step 5 (live generation) validates. This test proves the pipeline
 * does not hard-fail or fabricate ghosts when a tenant is bare.
 */
