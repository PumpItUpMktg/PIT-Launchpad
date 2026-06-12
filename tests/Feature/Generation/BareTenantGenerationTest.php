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
});
