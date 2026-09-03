<?php

use App\Enums\ReviewStatus;
use App\Integrations\Census\Geocoder;
use App\Integrations\Census\GeocodeResult;
use App\Jobs\GeocodeReview;
use App\Models\Location;
use App\Models\Review;
use App\Models\ReviewImport;
use App\Models\Scopes\SiteScope;
use App\Models\Site;
use App\Reviews\Import\ReviewImporter;
use App\Reviews\Publish\DbLocalReviewProvider;
use App\Support\CurrentSite;
use Illuminate\Support\Facades\Queue;

/** A deterministic geocoder for the geo tests (no network). */
function stubGeocoder(float $lat, float $lng, string $matched = ''): Geocoder
{
    return new class($lat, $lng, $matched) implements Geocoder
    {
        public function __construct(private float $lat, private float $lng, private string $matched) {}

        public function geocode(string $address): ?GeocodeResult
        {
            return new GeocodeResult($this->lat, $this->lng, $this->matched !== '' ? $this->matched : $address);
        }
    };
}

test('the importer stores each review own town/state/zip and queues a geocode', function () {
    Queue::fake();
    $site = Site::factory()->create();
    CurrentSite::set($site->id);
    Location::factory()->for($site)->create(['served_towns' => [['name' => 'Trooper', 'state' => 'PA']]]);

    $rows = [[
        'rating' => '5', 'body' => 'Great sump pump work, very clean', 'reviewed_at' => '2026-06-01',
        'name' => 'John Smith', 'city' => 'Trooper', 'state' => 'PA', 'zip' => '19403',
    ]];
    $mapping = array_combine(ReviewImporter::FIELDS, ReviewImporter::FIELDS);
    $import = ReviewImport::factory()->for($site)->create();

    app(ReviewImporter::class)->import($site, $rows, $mapping, 'google', $import);

    $review = Review::query()->withoutGlobalScope(SiteScope::class)->where('site_id', $site->id)->firstOrFail();
    expect($review->town)->toBe('Trooper')
        ->and($review->state)->toBe('PA')
        ->and($review->postal_code)->toBe('19403');
    Queue::assertPushed(GeocodeReview::class, fn (GeocodeReview $job): bool => $job->reviewId === (string) $review->id);
});

test('the importer splits a "Town, ST" city when no separate state column is mapped', function () {
    Queue::fake();
    $site = Site::factory()->create();
    CurrentSite::set($site->id);

    $rows = [['rating' => '4', 'body' => 'Right on time and tidy work', 'reviewed_at' => '2026-05-01', 'city' => 'Trooper, PA']];
    $mapping = ['rating' => 'rating', 'body' => 'body', 'reviewed_at' => 'reviewed_at', 'city' => 'city'];
    $import = ReviewImport::factory()->for($site)->create();

    app(ReviewImporter::class)->import($site, $rows, $mapping, null, $import);

    $review = Review::query()->withoutGlobalScope(SiteScope::class)->where('site_id', $site->id)->firstOrFail();
    expect($review->town)->toBe('Trooper')->and($review->state)->toBe('PA');
});

test('GeocodeReview fills the point from town/state/zip and is idempotent', function () {
    $site = Site::factory()->create();
    CurrentSite::set($site->id);
    $review = Review::factory()->for($site)->create(['town' => 'Trooper', 'state' => 'PA', 'postal_code' => '19403', 'lat' => null, 'lng' => null]);

    (new GeocodeReview((string) $review->id))->handle(stubGeocoder(40.1, -75.3));
    expect((float) $review->fresh()->lat)->toBe(40.1)->and((float) $review->fresh()->lng)->toBe(-75.3);

    // Already geocoded → the geocoder is never called (a throwing one proves the idempotency guard).
    (new GeocodeReview((string) $review->id))->handle(new class implements Geocoder
    {
        public function geocode(string $address): ?GeocodeResult
        {
            throw new RuntimeException('geocode must not be called for an already-geocoded review');
        }
    });
    expect((float) $review->fresh()->lat)->toBe(40.1);
});

test('GeocodeReview derives a first-party review town/state from its service address', function () {
    $site = Site::factory()->create();
    CurrentSite::set($site->id);
    $review = Review::factory()->for($site)->create(['town' => null, 'state' => null, 'service_address' => '123 Main St, Belleville, NJ', 'lat' => null, 'lng' => null]);

    (new GeocodeReview((string) $review->id))->handle(stubGeocoder(40.79, -74.15, '123 Main St, Belleville, NJ 07109, USA'));

    $fresh = $review->fresh();
    expect($fresh->town)->toBe('Belleville')->and($fresh->state)->toBe('NJ')->and((float) $fresh->lat)->toBe(40.79);
});

test('a location review is included when its own town the location serves, and shows its OWN town', function () {
    $site = Site::factory()->create();
    CurrentSite::set($site->id);
    $location = Location::factory()->for($site)->create(['served_towns' => [['name' => 'Belleville', 'state' => 'NJ']], 'lat' => 40.87, 'lng' => -74.15]);
    Review::factory()->for($site)->create([
        'status' => ReviewStatus::Published, 'town' => 'Belleville', 'state' => 'NJ',
        'customer_name' => 'John S.', 'rating' => 5, 'body' => 'Great', 'reviewed_at' => '2026-06-01',
    ]);

    $reviews = app(DbLocalReviewProvider::class)->for($location->fresh());

    expect($reviews)->toHaveCount(1)->and($reviews[0]->town)->toBe('Belleville'); // its own town, never the location city
});

test('a location review is included by 20mi radius when the town is not served, and a far one is excluded', function () {
    $site = Site::factory()->create();
    CurrentSite::set($site->id);
    $location = Location::factory()->for($site)->create(['served_towns' => [], 'lat' => 40.0, 'lng' => -74.0]);

    Review::factory()->for($site)->create(['status' => ReviewStatus::Published, 'town' => 'Zzznear', 'lat' => 40.05, 'lng' => -74.05, 'rating' => 5, 'body' => 'a', 'customer_name' => 'A B.', 'reviewed_at' => '2026-06-02']); // ~4mi
    Review::factory()->for($site)->create(['status' => ReviewStatus::Published, 'town' => 'Zzzfar', 'lat' => 42.0, 'lng' => -76.0, 'rating' => 5, 'body' => 'b', 'customer_name' => 'C D.', 'reviewed_at' => '2026-06-01']); // ~150mi

    $reviews = app(DbLocalReviewProvider::class)->for($location->fresh());

    expect($reviews)->toHaveCount(1)->and($reviews[0]->town)->toBe('Zzznear');
});

test('a location review with neither a served town nor a point is excluded (pre-backfill)', function () {
    $site = Site::factory()->create();
    CurrentSite::set($site->id);
    $location = Location::factory()->for($site)->create(['served_towns' => [['name' => 'Belleville']], 'lat' => 40.0, 'lng' => -74.0]);
    Review::factory()->for($site)->create(['status' => ReviewStatus::Published, 'town' => 'Zzzunserved', 'lat' => null, 'lng' => null, 'rating' => 5, 'body' => 'c', 'customer_name' => 'E F.', 'reviewed_at' => '2026-06-01']);

    expect(app(DbLocalReviewProvider::class)->for($location->fresh()))->toHaveCount(0);
});
