<?php

namespace Database\Seeders;

use App\Models\WireframeKit;
use App\PageBuilder\Schema\KitSchema;
use Illuminate\Database\Seeder;

/**
 * Seeds the two locked library-level kits (service-page, location-page) from
 * their JSON definitions in database/data/wireframe-kits. The schema is parsed
 * through the value objects and re-serialized, so what lands in the database is
 * exactly what the validation engine reads back.
 */
class WireframeKitSeeder extends Seeder
{
    private const KITS = ['service-page', 'location-page'];

    public function run(): void
    {
        foreach (self::KITS as $name) {
            $path = database_path("data/wireframe-kits/{$name}.json");
            $raw = json_decode((string) file_get_contents($path), true);

            $schema = KitSchema::fromArray($raw);

            WireframeKit::updateOrCreate(
                [
                    'site_id' => null,
                    'page_type' => $schema->pageType->value,
                    'version' => $schema->version,
                ],
                [
                    'name' => $schema->name,
                    'elementor_template_ref' => $schema->elementorTemplateRef,
                    'seo_profile_ref' => $schema->seoProfileRef,
                    'slot_schema' => $schema->toArray(),
                ],
            );
        }
    }
}
