<?php

namespace Tests\Support;

use App\Enums\ProofType;
use App\Models\Content;
use App\Models\ConversionConfig;
use App\Models\Location;
use App\Models\Market;
use App\Models\ProofItem;
use App\Models\Service;
use App\Models\Site;
use App\Models\WireframeKit;
use App\PageBuilder\Schema\KitSchema;
use App\PageBuilder\Validation\ValidationContext;

/**
 * Test fixtures for the §3a Page Builder content contract: kit schemas loaded
 * from the seed JSON and a fully entity-backed site so structural failures can
 * be isolated from entity-resolution failures.
 */
class PageBuilder
{
    public static function serviceKit(): KitSchema
    {
        return self::kitFromFile('service-page');
    }

    public static function locationKit(): KitSchema
    {
        return self::kitFromFile('location-page');
    }

    public static function kitFromFile(string $name): KitSchema
    {
        $raw = json_decode((string) file_get_contents(database_path("data/wireframe-kits/{$name}.json")), true);

        return KitSchema::fromArray($raw);
    }

    /**
     * A site wired with every entity the two kits resolve against, plus a Content
     * row and Market for the validation context.
     *
     * @return array{site: Site, market: Market, content: Content}
     */
    public static function backedSite(): array
    {
        $site = Site::factory()->create();
        $market = Market::factory()->priority()->create(['site_id' => $site->id]);

        // Substantiated proof (proof_strip needs >= 2, why_us needs >= 1).
        ProofItem::factory()->count(2)->create([
            'site_id' => $site->id,
            'is_substantiated' => true,
        ]);

        // A market-tagged review (reviews.market >= 1).
        $review = ProofItem::factory()->create([
            'site_id' => $site->id,
            'type' => ProofType::Testimonial,
            'is_substantiated' => true,
        ]);
        $review->markets()->attach($market->id);

        ConversionConfig::factory()->create([
            'site_id' => $site->id,
            'primary_actions' => ['call', 'book'],
        ]);

        Location::factory()->create([
            'site_id' => $site->id,
            'is_storefront' => true,
        ]);

        Service::factory()->count(3)->create(['site_id' => $site->id]);

        // The page targets this market — reviews.market resolves against
        // content.market_id (a site-wide review would also count).
        $content = Content::factory()->create(['site_id' => $site->id, 'market_id' => $market->id]);

        return ['site' => $site, 'market' => $market, 'content' => $content];
    }

    /**
     * @param  array{site: Site, market: Market, content: Content}  $backed
     */
    public static function context(array $backed, bool $storefront = true): ValidationContext
    {
        // backedSite() wires every entity, so all the §3a condition flags are
        // satisfied — the conditional slots (cta / contact_block / proof_strip /
        // testimonial / why_us) all apply and validate against the backing data.
        return new ValidationContext(
            content: $backed['content'],
            market: $backed['market'],
            flags: [
                'is_storefront' => $storefront,
                'has_reviews' => true,
                'has_proof' => true,
                'has_substantiated_proof' => true,
                'has_location' => true,
                'has_location_phone' => true,
            ],
        );
    }

    /**
     * A structurally valid payload for the service kit (entity slots resolve
     * from the database, so they are intentionally absent here).
     *
     * @return array<string, mixed>
     */
    public static function validServicePayload(): array
    {
        return [
            'hero_problem' => 'Leaking water heater flooding your garage?',
            'hero_solution' => 'Fast, guaranteed water heater repair, often the same day.',
            'hero_image' => ['src' => 'hero.webp', 'alt' => 'Technician repairing a water heater', 'width' => 1200, 'height' => 675],
            'problem_explainer' => str_repeat('A failing water heater disrupts every routine in the home. ', 4),
            'solution_overview' => '<p>'.str_repeat('Our licensed team diagnoses and fixes it fast. ', 5).'</p>',
            'service_features' => ['Same-day service', 'Licensed technicians', 'Upfront pricing', 'Workmanship warranty'],
            'process_steps' => ['Diagnose the fault', 'Quote upfront', 'Repair and test'],
            'why_us' => str_repeat('Licensed, insured, and fully warrantied work. ', 3),
            'faq' => [
                ['question' => 'How fast can you come out?', 'answer' => 'Often the same day.'],
                ['question' => 'How much does it cost?', 'answer' => 'You get an upfront quote first.'],
                ['question' => 'Is the work guaranteed?', 'answer' => 'Yes — parts and labor.'],
            ],
        ];
    }

    /**
     * A structurally valid payload for the location kit.
     *
     * @return array<string, mixed>
     */
    public static function validLocationPayload(): array
    {
        return [
            'hero_heading' => 'Water Heater Repair in Austin',
            'hero_image' => ['src' => 'austin.webp', 'alt' => 'Austin neighborhood street', 'width' => 1200, 'height' => 675],
            'area_intro' => str_repeat('We serve Austin neighborhoods with prompt, local service. ', 3),
            'why_us_local' => str_repeat('Local, licensed, and warrantied in your community. ', 3),
        ];
    }

    /**
     * Seed the two locked kits and return the persisted service kit row.
     */
    public static function seedServiceKitModel(): WireframeKit
    {
        $schema = self::serviceKit();

        return WireframeKit::create([
            'site_id' => null,
            'name' => $schema->name,
            'page_type' => $schema->pageType->value,
            'version' => $schema->version,
            'elementor_template_ref' => $schema->elementorTemplateRef,
            'seo_profile_ref' => $schema->seoProfileRef,
            'slot_schema' => $schema->toArray(),
        ]);
    }
}
