<?php

use App\Enums\ContentKind;
use App\Enums\PageType;
use App\Enums\ReviewStatus;
use App\Local\Proof\LocalReview;
use App\Local\Proof\LocalReviewProvider;
use App\Local\Proof\ServiceReviewProvider;
use App\Models\Content;
use App\Models\Location;
use App\Models\Review;
use App\Models\Service;
use App\Models\Site;
use App\Publishing\Schema\LocationSchemaBuilder;
use App\Publishing\Schema\ServiceSchemaBuilder;
use App\Reviews\Publish\DbLocalReviewProvider;
use App\Reviews\Publish\DbServiceReviewProvider;
use App\Support\CurrentSite;

test('the real review providers are bound (replacing the null providers)', function (): void {
    expect(app(LocalReviewProvider::class))->toBeInstanceOf(DbLocalReviewProvider::class)
        ->and(app(ServiceReviewProvider::class))->toBeInstanceOf(DbServiceReviewProvider::class);
});

test('the local provider returns only published reviews for the location, mapped to the DTO', function (): void {
    $site = Site::factory()->create();
    CurrentSite::set($site->id);
    $location = Location::factory()->for($site)->create([
        'address_components' => [['long_name' => 'Trooper', 'types' => ['locality']], ['short_name' => 'PA', 'types' => ['administrative_area_level_1']]],
    ]);
    $service = Service::factory()->for($site)->create(['name' => 'Sump Pumps']);

    $published = Review::factory()->for($site)->published()->create([
        'location_id' => $location->id, 'town' => 'Trooper', 'state' => 'PA', // its own town — a town the location serves
        'customer_name' => 'John D.', 'rating' => 5, 'body' => 'Great work', 'reviewed_at' => '2026-06-01',
    ]);
    $published->services()->attach($service->id);

    Review::factory()->for($site)->create(['location_id' => $location->id, 'status' => ReviewStatus::Pending]);           // excluded
    Review::factory()->for($site)->create(['location_id' => $location->id, 'status' => ReviewStatus::Approved]);          // excluded (not published)
    Review::factory()->for($site)->published()->create(['location_id' => Location::factory()->for($site)->create()->id]); // other location

    $reviews = app(LocalReviewProvider::class)->for($location);

    expect($reviews)->toHaveCount(1)
        ->and($reviews[0])->toBeInstanceOf(LocalReview::class)
        ->and($reviews[0]->authorFirst)->toBe('John D.')
        ->and($reviews[0]->rating)->toBe(5)
        ->and($reviews[0]->text)->toBe('Great work')
        ->and($reviews[0]->town)->toBe('Trooper')
        ->and($reviews[0]->service)->toBe('Sump Pumps')
        ->and($reviews[0]->date)->toBe('2026-06-01');
});

test('the service provider returns only published reviews tagged with the service', function (): void {
    $site = Site::factory()->create();
    CurrentSite::set($site->id);
    $location = Location::factory()->for($site)->create();
    $service = Service::factory()->for($site)->create();
    $other = Service::factory()->for($site)->create();

    $tagged = Review::factory()->for($site)->published()->create(['location_id' => $location->id]);
    $tagged->services()->attach($service->id);
    Review::factory()->for($site)->published()->create(['location_id' => $location->id])->services()->attach($other->id); // different service
    Review::factory()->for($site)->create(['location_id' => $location->id, 'status' => ReviewStatus::Pending])->services()->attach($service->id); // pending

    $reviews = app(ServiceReviewProvider::class)->for($service);

    expect($reviews)->toHaveCount(1)
        ->and($reviews[0]->service)->toBe($service->name);
});

test('the schema builders emit NO review / aggregateRating markup even with published reviews present (§8)', function (): void {
    $site = Site::factory()->create();
    CurrentSite::set($site->id);
    $location = Location::factory()->for($site)->create(['place_id' => 'ChIJabc']);
    $service = Service::factory()->for($site)->create();
    $locationPage = Content::factory()->create(['site_id' => $site->id, 'kind' => ContentKind::Page, 'page_type' => PageType::Location, 'location_id' => $location->id]);
    $servicePage = Content::factory()->create(['site_id' => $site->id, 'kind' => ContentKind::Page, 'page_type' => PageType::Service, 'primary_service_id' => $service->id]);

    // Review data now exists — the exact trigger the old TODO named. Markup must STILL be absent.
    $r = Review::factory()->for($site)->published()->create(['location_id' => $location->id, 'rating' => 5]);
    $r->services()->attach($service->id);

    $locJson = (string) json_encode(app(LocationSchemaBuilder::class)->buildForLocation($locationPage, $location, $site, 'https://example.com', null));
    $svcJson = (string) json_encode(app(ServiceSchemaBuilder::class)->build($servicePage, $site, 'https://example.com', null));

    foreach ([$locJson, $svcJson] as $json) {
        expect(strtolower($json))->not->toContain('aggregaterating')
            ->and(strtolower($json))->not->toContain('ratingvalue')
            ->and(strtolower($json))->not->toContain('"review"');
    }
});
