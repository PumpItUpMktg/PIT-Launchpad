<?php

use App\Enums\ConnectionProvider;
use App\Enums\ContentStatus;
use App\Models\Connection;
use App\Models\Content;
use App\Models\Silo;
use App\Models\Site;
use App\Models\WireframeKit;
use App\PageBuilder\Schema\KitSchema;
use App\Publishing\MetaBlobAssembler;
use App\Publishing\PublishContentService;
use App\Publishing\RenderCoordinator;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Http;

/**
 * Regression for the launch-surfaced content-shape bug. The demo seeder's
 * WireframeKit (via the factory) carries the simple `{slot: constraints}`
 * slot_schema with NO kit-level name/page_type — the shape real persisted kits
 * can have. KitSchema::fromArray used to fatal on it (Undefined array key "name",
 * then PageType::from('')). The existing MetaBlobTest never caught it because it
 * used a hand-built rich-format kit. These pin the real seeded shape in CI.
 */
it('parses a real-shaped kit slot_schema (no name/page_type) without fatalling', function () {
    $kit = WireframeKit::factory()->create(); // simple {hero: ..., body: ...} schema

    $schema = $kit->schema();

    expect($schema)->toBeInstanceOf(KitSchema::class)
        ->and($schema->name)->toBe('')
        ->and($schema->pageType)->toBeNull()
        ->and($schema->slots)->toBe([]);
});

it('assembles the /content payload for seeded content carrying a real-shaped kit', function () {
    $site = Site::factory()->create(['domain_url' => 'https://apex.example']);
    $silo = Silo::factory()->create(['site_id' => $site->id, 'name' => 'Plumbing']);
    $kit = WireframeKit::factory()->create();
    $content = Content::factory()->page()->create([
        'site_id' => $site->id,
        'silo_id' => $silo->id,
        'wireframe_kit_id' => $kit->id,
        'title' => 'Water Heater Repair in Austin',
        'slug' => 'water-heater-repair-austin',
        'status' => ContentStatus::Published,
    ]);

    $payload = app(MetaBlobAssembler::class)->assemble($content, new Collection);

    expect($payload)->toHaveKeys([
        'content_id', 'kind', 'page_type', 'kit', 'kit_version',
        'silo_id', 'slug', 'status', 'locked', 'slot_payload', 'images', 'seo',
    ]);
    expect($payload['seo']['breadcrumbs'])->toContain(['name' => 'Plumbing', 'url' => '']);

    // Rendering calls schema() unconditionally — it must not throw either.
    expect(app(RenderCoordinator::class)->render($content)->isBlocked())->toBeFalse();
});

it('publishes seeded content with a real-shaped kit end to end', function () {
    Http::fake(['*/launchpad/v1/content' => Http::response(['wp_post_id' => 55, 'status' => 'publish', 'skipped' => false])]);

    $site = Site::factory()->create(['domain_url' => 'https://apex.example']);
    Connection::factory()->rotated()->create([
        'site_id' => $site->id,
        'provider' => ConnectionProvider::WpAppPassword->value,
        'credentials' => ['base_url' => 'https://apex.example', 'username' => 'u', 'app_password' => 'p'],
    ]);
    $kit = WireframeKit::factory()->create();
    $content = Content::factory()->page()->create([
        'site_id' => $site->id,
        'wireframe_kit_id' => $kit->id,
        'status' => ContentStatus::Approved,
        'title' => 'Water Heater Repair in Austin',
        'slug' => 'water-heater-repair-austin',
    ]);

    $result = app(PublishContentService::class)->publish($content);

    expect($result->isPublished())->toBeTrue()
        ->and($result->wpPostId)->toBe(55);
});
