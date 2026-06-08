<?php

namespace Tests\Support;

use App\ContentEngine\Drafting\Drafter;
use App\ContentEngine\Drafting\DraftingEngine;
use App\ContentEngine\Drafting\DraftRequest;
use App\ContentEngine\Drafting\GroundingAssembler;
use App\ContentEngine\Drafting\VerificationPass;
use App\Enums\ContentKind;
use App\Enums\DraftTrigger;
use App\Enums\IntakeType;
use App\Enums\ProofType;
use App\Integrations\Claude\ClaudeClient;
use App\Models\ProofItem;
use App\Models\Site;
use App\Models\VoiceProfile;

/**
 * Shared scaffolding for the §6b drafting tests: a real engine wired to a fake
 * Claude seam, plus a tenant fixture (active voice profile + one substantiated
 * claim) and a canonical reactive request.
 */
class DraftingHarness
{
    public static function engine(ClaudeClient $claude): DraftingEngine
    {
        return new DraftingEngine(new GroundingAssembler, new Drafter($claude), new VerificationPass);
    }

    /**
     * A site with an active voice profile (version 3) and one substantiated
     * warranty claim.
     *
     * @return array{site: Site, claim: ProofItem}
     */
    public static function fixture(): array
    {
        $site = Site::factory()->create();
        VoiceProfile::factory()->active()->create(['site_id' => $site->id, 'version' => 3]);
        $claim = ProofItem::factory()->create([
            'site_id' => $site->id,
            'type' => ProofType::Warranty,
            'payload' => ['label' => '10-year installation warranty'],
            'is_substantiated' => true,
        ]);

        return ['site' => $site, 'claim' => $claim];
    }

    public static function postRequest(Site $site): DraftRequest
    {
        return new DraftRequest(
            siteId: $site->id,
            kind: ContentKind::Post,
            intakeType: IntakeType::Reactive,
            trigger: DraftTrigger::News,
            title: 'New tankless water heater rebate announced',
            angleHint: 'How the rebate saves homeowners money',
            sourceName: 'Local Tribune',
            sourceUrl: 'https://localtribune.example/rebate-story',
        );
    }
}
