<?php

namespace Tests\Support;

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

/**
 * A coherent kind=page Content row wired to a full intake set (services +
 * problems, offer, market, substantiated proof, branding, active voice) and the
 * seeded service kit — the fixture the page-generation tests draft against. The
 * page starts UNDRAFTED (empty slot_payload).
 *
 * @phpstan-type Overrides array<string, mixed>
 */
final class PageFixture
{
    /**
     * @param  array<string, mixed>  $overrides
     */
    public static function intakePage(array $overrides = []): Content
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

        return Content::factory()->page()->create(array_merge([
            'site_id' => $site->id,
            'silo_id' => $silo->id,
            'wireframe_kit_id' => $kit->id,
            'page_type' => PageType::Service,
            'slot_payload' => [],
        ], $overrides));
    }

    /**
     * A drafter response that fills every generated/grounded required slot of the
     * service kit (entity + media slots resolve downstream, not here).
     *
     * @param  array<string, mixed>  $slotOverrides
     */
    public static function validResponse(string $claimId, array $slotOverrides = []): string
    {
        return Draft::json([
            'slots' => array_merge([
                'hero_headline' => 'Tankless water heater installation',
                'hero_subhead' => 'Endless on-demand hot water, installed cleanly in a single visit.',
                'svc_intro' => 'An aging water heater rarely fails politely. It declines for months — lukewarm showers, a creeping utility bill, '
                    .'rusty water, then a sudden cold morning. We right-size a modern tankless system to your household demand and install it cleanly in a single visit.',
                'symptoms_intro' => 'If any of these sound familiar, the tank is telling you something.',
                'scope_intro' => 'Every installation includes the full job — no surprise add-ons.',
                'cost_copy' => 'Final price depends on the unit size, venting route, and your existing gas line — you get a firm written quote before any work starts.',
                'faq' => [
                    ['question' => 'How long does install take?', 'answer' => 'Most installs are same-day.'],
                    ['question' => 'Will it lower my bills?', 'answer' => 'Tankless heats on demand, cutting standby loss.'],
                    ['question' => 'Do you haul the old unit?', 'answer' => 'Yes, removal and disposal are included.'],
                ],
            ], $slotOverrides),
            'images' => [[
                'slot' => 'hero_image',
                'prompt' => 'A technician installing a wall-mounted tankless water heater',
                'seo_filename' => 'tankless-install.jpg',
                'alt' => 'Technician installing a tankless water heater',
            ]],
            'claims_used' => [['text' => '10-year warranty', 'claim_id' => $claimId]],
        ]);
    }
}
