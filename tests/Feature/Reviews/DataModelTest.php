<?php

use App\Enums\ReviewSource;
use App\Enums\ReviewStatus;
use App\Models\Location;
use App\Models\Review;
use App\Models\ReviewRequest;
use App\Models\Service;
use App\Models\Site;
use App\Reviews\Resolution\ReviewLocationResolver;
use App\Support\CurrentSite;
use Illuminate\Database\QueryException;

test('a review casts its enums, rating, flags and dates', function (): void {
    $site = Site::factory()->create();
    CurrentSite::set($site->id);
    $review = Review::factory()->for($site)->published()->create(['rating' => 5]);

    expect($review->source)->toBe(ReviewSource::FirstParty)
        ->and($review->status)->toBe(ReviewStatus::Published)
        ->and($review->rating)->toBe(5)
        ->and($review->needs_location)->toBeFalse()
        ->and($review->published_at)->not->toBeNull()
        ->and(Review::MAX_SERVICES)->toBe(3);
});

test('a review tags up to its services via the pivot', function (): void {
    $site = Site::factory()->create();
    CurrentSite::set($site->id);
    $review = Review::factory()->for($site)->create();
    $a = Service::factory()->for($site)->create();
    $b = Service::factory()->for($site)->create();

    $review->services()->attach([$a->id, $b->id]);

    expect($review->services()->pluck('services.id')->all())->toEqualCanonicalizing([$a->id, $b->id]);
});

test('the DB guards one request per (site, external_ref); null external_ref is unconstrained', function (): void {
    $site = Site::factory()->create();
    CurrentSite::set($site->id);

    ReviewRequest::factory()->for($site)->create(['external_ref' => 'job-1']);
    // A redelivery of the same job cannot create a second request row.
    expect(fn () => ReviewRequest::factory()->for($site)->create(['external_ref' => 'job-1']))
        ->toThrow(QueryException::class);

    // But requests without an external_ref (pure manual) are not constrained.
    ReviewRequest::factory()->for($site)->create(['external_ref' => null]);
    ReviewRequest::factory()->for($site)->create(['external_ref' => null]);
    expect(ReviewRequest::query()->whereNull('external_ref')->count())->toBe(2);
});

test('the request payload round-trips as an array', function (): void {
    $site = Site::factory()->create();
    CurrentSite::set($site->id);
    $req = ReviewRequest::factory()->for($site)->create(['payload' => ['customer_email' => 'a@b.com', 'service_ids' => ['s1']]]);

    expect($req->fresh()->payload)->toBe(['customer_email' => 'a@b.com', 'service_ids' => ['s1']]);
});

test('the resolver maps a served town to its owning location', function (): void {
    $site = Site::factory()->create();
    CurrentSite::set($site->id);
    Location::factory()->for($site)->create(['name' => 'Elsewhere HQ', 'served_towns' => [['name' => 'Norristown', 'state' => 'PA']]]);
    $trooper = Location::factory()->for($site)->create(['name' => 'Trooper Shop', 'served_towns' => [['name' => 'Trooper', 'state' => 'PA']]]);

    expect(app(ReviewLocationResolver::class)->resolve($site, 'Trooper, PA'))->toBe((string) $trooper->id)
        ->and(app(ReviewLocationResolver::class)->resolve($site, 'Nowhere, PA'))->toBeNull(); // multi-location, unmatched => needs_location
});

test('a single-location tenant resolves any town to its sole location', function (): void {
    $site = Site::factory()->create();
    CurrentSite::set($site->id);
    $only = Location::factory()->for($site)->create(['served_towns' => []]);

    expect(app(ReviewLocationResolver::class)->resolve($site, 'Anytown, PA'))->toBe((string) $only->id);
});
