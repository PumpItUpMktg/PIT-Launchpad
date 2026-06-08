<?php

namespace Tests\Support;

use App\Enums\ContentKind;
use App\Enums\ContentStatus;
use App\Enums\PageType;
use App\Integrations\Fal\FalClient;
use App\Integrations\Fal\MockFalClient;
use App\Integrations\Vision\MockVisionClient;
use App\Integrations\Vision\VisionClient;
use App\Models\Connection;
use App\Models\Content;
use App\Models\Site;
use App\Models\WireframeKit;
use Database\Seeders\WireframeKitSeeder;
use Illuminate\Support\Facades\Storage;

/**
 * Shared scaffolding for the §2 publish tests: a site with a WP connection, a
 * seeded kit, an approved page with image specs, plus the mocked render adapters
 * and a faked R2 disk so the pipeline runs without a network or real storage.
 */
class PublishHarness
{
    public static function fakeAdapters(): void
    {
        Storage::fake('r2');
        app()->bind(FalClient::class, MockFalClient::class);
        app()->bind(VisionClient::class, MockVisionClient::class);
    }

    /**
     * @param  array<string, mixed>  $credentials
     */
    public static function site(array $credentials = []): Site
    {
        $site = Site::factory()->create(['domain_url' => 'https://apex.example']);

        Connection::factory()->create([
            'site_id' => $site->id,
            'provider' => 'wp_app_password',
            'credentials' => array_merge([
                'base_url' => 'https://wp.apex.example',
                'username' => 'launchpad-service',
                'app_password' => 'abcd efgh ijkl mnop',
            ], $credentials),
        ]);

        return $site;
    }

    public static function approvedPage(Site $site): Content
    {
        (new WireframeKitSeeder)->run();
        $kit = WireframeKit::where('page_type', 'service')->firstOrFail();

        return Content::factory()->create([
            'site_id' => $site->id,
            'kind' => ContentKind::Page,
            'page_type' => PageType::Service,
            'status' => ContentStatus::Approved,
            'slug' => 'water-heater-repair-austin',
            'title' => 'Water Heater Repair in Austin',
            'wireframe_kit_id' => $kit->id,
            'wireframe_kit_version' => 1,
            'schema_type' => 'Service',
            'schema_payload' => ['@type' => 'Service', 'name' => 'Water Heater Repair'],
            'slot_payload' => [
                'hero_problem' => 'Leaking water heater flooding your garage?',
                'hero_solution' => 'Fast, guaranteed water heater repair, often the same day.',
                'service_features' => ['Same-day service', 'Licensed technicians'],
                'why_us' => 'Licensed, insured, and fully warrantied.',
            ],
            'meta' => [
                'seo' => [
                    'title' => 'Water Heater Repair in Austin | Apex',
                    'meta_description' => 'Fast, guaranteed water heater repair in Austin.',
                ],
                'image_specs' => [[
                    'slot' => 'hero_image',
                    'prompt' => 'A technician repairing a residential water heater',
                    'seo_filename' => 'water-heater-repair-austin-hero.webp',
                    'alt' => 'Technician repairing a residential water heater',
                    'title' => 'Water heater repair in Austin',
                    'caption' => 'Same-day water heater repair',
                ]],
            ],
        ]);
    }
}
