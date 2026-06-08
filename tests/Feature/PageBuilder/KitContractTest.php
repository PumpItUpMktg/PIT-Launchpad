<?php

use App\Enums\PageType;
use App\Enums\SlotSource;
use App\Models\WireframeKit;
use App\PageBuilder\Schema\KitSchema;
use Database\Seeders\WireframeKitSeeder;
use Tests\Support\PageBuilder;

test('both kits round-trip losslessly through the value objects', function (string $file, PageType $pageType) {
    $raw = json_decode((string) file_get_contents(database_path("data/wireframe-kits/{$file}.json")), true);

    $schema = KitSchema::fromArray($raw);

    expect($schema->pageType)->toBe($pageType)
        ->and($schema->version)->toBe(1)
        ->and($schema->slots)->toHaveCount(13);

    // Re-parsing the serialized form yields an identical structure.
    $reparsed = KitSchema::fromArray($schema->toArray());

    expect($reparsed->toArray())->toBe($schema->toArray());
})->with([
    ['service-page', PageType::Service],
    ['location-page', PageType::Location],
]);

test('the location kit has exactly three generating slots', function () {
    $generating = array_filter(
        PageBuilder::locationKit()->slots,
        fn ($slot) => in_array($slot->source, [SlotSource::Generated, SlotSource::Grounded], true),
    );

    expect($generating)->toHaveCount(3);
});

test('location nap and map slots are conditional on is_storefront', function () {
    $kit = PageBuilder::locationKit();

    foreach (['nap_block', 'map'] as $key) {
        $slot = $kit->slot($key);
        expect($slot)->not->toBeNull()
            ->and($slot->condition)->not->toBeNull()
            ->and($slot->appliesTo(['is_storefront' => true]))->toBeTrue()
            ->and($slot->appliesTo(['is_storefront' => false]))->toBeFalse();
    }
});

test('the seeder persists both locked kits idempotently', function () {
    $this->seed(WireframeKitSeeder::class);
    $this->seed(WireframeKitSeeder::class);

    expect(WireframeKit::whereNotNull('page_type')->count())->toBe(2);

    $service = WireframeKit::where('page_type', 'service')->sole();

    expect($service->version)->toBe(1)
        ->and($service->schema()->slots)->toHaveCount(13)
        ->and($service->slot_schema)->toBe($service->schema()->toArray());
});
