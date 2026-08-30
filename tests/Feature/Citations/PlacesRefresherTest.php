<?php

use App\Citations\PlacesRefresher;
use App\Console\Commands\RefreshPlacesCommand;
use App\Integrations\Places\MockPlacesProvider;
use App\Integrations\Places\PlacesProvider;
use App\Jobs\RefreshLocationPlaces;
use App\Models\Location;
use App\Models\LocationNapProfile;
use App\Models\Scopes\SiteScope;
use App\Models\Site;
use App\Support\CurrentSite;
use Illuminate\Support\Facades\Queue;

beforeEach(function (): void {
    app()->bind(PlacesProvider::class, MockPlacesProvider::class);
});

function placeBackedLocation(array $overrides = []): Location
{
    $site = Site::factory()->create();
    CurrentSite::set($site->id);

    return Location::factory()->create(array_merge([
        'site_id' => $site->id,
        'name' => 'Stale Name',
        'phone' => null,
        'website' => null,
        'place_id' => MockPlacesProvider::PLACE_ID,
        'gbp_url' => null,
        'address' => null,
        'address_components' => [],
        'hours' => [],
    ], $overrides));
}

function napFor(Location $location): ?LocationNapProfile
{
    return LocationNapProfile::query()->withoutGlobalScope(SiteScope::class)
        ->where('location_id', $location->id)->first();
}

test('a refresh pulls fresh GBP data onto the location and seeds the NAP', function (): void {
    $location = placeBackedLocation();

    $result = app(PlacesRefresher::class)->refresh($location);

    expect($result->refreshed())->toBeTrue()
        ->and($result->fields)->toContain('name')
        ->and($result->fields)->toContain('website');

    $fresh = Location::query()->withoutGlobalScope(SiteScope::class)->find($location->id);
    expect($fresh->name)->toBe('Apex Plumbing — Austin')
        ->and($fresh->website)->toBe('https://apexplumbing.example')
        ->and($fresh->phone)->toBe('+15125550142');

    $nap = napFor($location);
    expect($nap)->not->toBeNull()
        ->and($nap->business_name)->toBe('Apex Plumbing — Austin')
        ->and($nap->address_1)->toBe('500 West 2nd Street')
        ->and($nap->website_url)->toBe('https://apexplumbing.example');
});

test('a refresh with no place id is a no-op', function (): void {
    $location = placeBackedLocation(['place_id' => null]);

    $result = app(PlacesRefresher::class)->refresh($location);

    expect($result->outcome)->toBe('no_place_id')
        ->and(napFor($location))->toBeNull();
});

test('a refresh for a place Google no longer resolves reports not_found', function (): void {
    $location = placeBackedLocation(['place_id' => 'ChIJUNKNOWN0000000000000000']);

    $result = app(PlacesRefresher::class)->refresh($location);

    expect($result->outcome)->toBe('not_found')
        ->and(napFor($location))->toBeNull();
});

test('a second refresh with unchanged GBP data changes nothing', function (): void {
    $location = placeBackedLocation();
    app(PlacesRefresher::class)->refresh($location);

    $again = app(PlacesRefresher::class)->refresh(
        Location::query()->withoutGlobalScope(SiteScope::class)->find($location->id)
    );

    expect($again->completed())->toBeTrue()
        ->and($again->refreshed())->toBeFalse()
        ->and($again->fields)->toBe([]);
});

test('the job refreshes the location and sets the tenant scope', function (): void {
    $location = placeBackedLocation();
    CurrentSite::set(null);

    (new RefreshLocationPlaces($location->id))->handle(app(PlacesRefresher::class));

    expect(napFor($location))->not->toBeNull();
});

test('the command queues a refresh only for GBP-backed locations', function (): void {
    Queue::fake();
    $site = Site::factory()->create();
    CurrentSite::set($site->id);
    $gbp = Location::factory()->create(['site_id' => $site->id, 'place_id' => MockPlacesProvider::PLACE_ID]);
    Location::factory()->create(['site_id' => $site->id, 'place_id' => null]); // manual — skipped

    $this->artisan(RefreshPlacesCommand::class, ['--all' => true])->assertSuccessful();

    Queue::assertPushed(RefreshLocationPlaces::class, 1);
    Queue::assertPushed(RefreshLocationPlaces::class, fn (RefreshLocationPlaces $job): bool => $job->locationId === $gbp->id);
});

test('the command errors without a target selector', function (): void {
    $this->artisan(RefreshPlacesCommand::class)->assertFailed();
});
