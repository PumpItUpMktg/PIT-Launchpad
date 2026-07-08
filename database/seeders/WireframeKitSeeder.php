<?php

namespace Database\Seeders;

use App\Models\WireframeKit;
use App\PageBuilder\Schema\KitSchema;
use Illuminate\Database\Seeder;

/**
 * Seeds the library-level kits from their JSON definitions in database/data/wireframe-kits: the two
 * locked page kits (service-page, location-page), the standard-page composer's kits (home, about,
 * why-choose-us, areas-we-serve, faq, privacy, terms), and the silo-pillar hub kit (hub-page). The schema is parsed through the value
 * objects and re-serialized, so what
 * lands in the database is exactly what the validation engine reads back. Keyed on (site_id, name,
 * version) — name is the kit identity, so several kits can share page_type='utility'.
 */
class WireframeKitSeeder extends Seeder
{
    private const KITS = [
        'service-page',
        'location-page',
        'home-page',
        'about-page',
        'why-choose-us-page',
        'areas-we-serve-page',
        'faq-page',
        'privacy-page',
        'terms-page',
        'hub-page',
    ];

    public function run(): void
    {
        foreach (self::KITS as $name) {
            $path = database_path("data/wireframe-kits/{$name}.json");
            $raw = json_decode((string) file_get_contents($path), true);

            $schema = KitSchema::fromArray($raw);

            WireframeKit::updateOrCreate(
                [
                    'site_id' => null,
                    'name' => $schema->name,
                    'version' => $schema->version,
                ],
                [
                    'page_type' => $schema->pageType?->value,
                    'elementor_template_ref' => $schema->elementorTemplateRef,
                    'seo_profile_ref' => $schema->seoProfileRef,
                    'slot_schema' => $schema->toArray(),
                ],
            );
        }
    }
}
