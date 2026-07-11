<?php

use App\Enums\PageType;
use App\Enums\SlotSource;
use App\Models\WireframeKit;
use App\PageBuilder\Schema\KitSchema;
use Database\Seeders\WireframeKitSeeder;
use Tests\Support\PageBuilder;

test('both kits round-trip losslessly through the value objects', function (string $file, PageType $pageType, int $slots) {
    $raw = json_decode((string) file_get_contents(database_path("data/wireframe-kits/{$file}.json")), true);

    $schema = KitSchema::fromArray($raw);

    expect($schema->pageType)->toBe($pageType)
        ->and($schema->version)->toBe(1)
        ->and($schema->slots)->toHaveCount($slots);

    // Re-parsing the serialized form yields an identical structure.
    $reparsed = KitSchema::fromArray($schema->toArray());

    expect($reparsed->toArray())->toBe($schema->toArray());
})->with([
    ['service-page', PageType::Service, 10], // block-era drafted slots + the two platform conversion slots (cta / contact_block)
    ['location-page', PageType::Location, 7], // block-era: drafted slots only (sections/NAP/schema live in the composer + blob)
]);

test('the block-era location kit generates six slots and stakes no entity slots', function () {
    $kit = PageBuilder::locationKit();

    $generating = array_filter(
        $kit->slots,
        fn ($slot) => in_array($slot->source, [SlotSource::Generated, SlotSource::Grounded], true),
    );

    // hero_headline / hero_subhead / loc_intro / loc_services_intro / loc_coverage / faq.
    expect($generating)->toHaveCount(6)
        // Reviews/jobs are provider-gated page SECTIONS (empty ⇒ omitted by the composer), and the
        // NAP/map ride the blob + LocalBusiness schema — the kit deliberately stakes no entity slot.
        ->and(array_filter($kit->slots, fn ($slot) => $slot->source === SlotSource::Entity))->toBe([]);
});

test('location nap and map slots are gone from the kit — storefront gating lives in the schema builder', function () {
    $kit = PageBuilder::locationKit();

    // The Elementor-era nap_block/map slots (is_storefront-conditional) moved out of the kit: the
    // composer + LocationSchemaBuilder gate PostalAddress/geo/hasMap on Location.is_storefront now.
    expect($kit->slot('nap_block'))->toBeNull()
        ->and($kit->slot('map'))->toBeNull();
});

test('the seeder persists the library kits idempotently (keyed by name, not page_type)', function () {
    $this->seed(WireframeKitSeeder::class);
    $this->seed(WireframeKitSeeder::class);

    // 2 locked page kits + 8 standard-page composer kits + 1 hub kit; re-seed updates rather than duplicates.
    expect(WireframeKit::whereNotNull('page_type')->count())->toBe(11)
        ->and(WireframeKit::where('page_type', 'utility')->count())->toBe(7)  // about / why-choose-us / areas-we-serve / faq / contact / privacy / terms share page_type
        ->and(WireframeKit::where('page_type', 'hub')->count())->toBe(1);

    $service = WireframeKit::where('page_type', 'service')->sole();

    expect($service->version)->toBe(1)
        ->and($service->schema()->slots)->toHaveCount(10)
        ->and($service->slot_schema)->toBe($service->schema()->toArray());
});
