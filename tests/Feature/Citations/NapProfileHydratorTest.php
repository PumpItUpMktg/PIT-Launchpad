<?php

use App\Citations\NapProfileHydrator;
use App\Models\Location;
use App\Models\LocationNapProfile;
use App\Models\Scopes\SiteScope;
use App\Models\Site;
use App\Support\CurrentSite;

/** Google's structured address components for a fully-resolved storefront. */
function gbpAddressComponents(): array
{
    return [
        ['long_name' => '500', 'short_name' => '500', 'types' => ['street_number']],
        ['long_name' => 'West 2nd Street', 'short_name' => 'W 2nd St', 'types' => ['route']],
        ['long_name' => 'Austin', 'short_name' => 'Austin', 'types' => ['locality']],
        ['long_name' => 'Texas', 'short_name' => 'TX', 'types' => ['administrative_area_level_1']],
        ['long_name' => '78701', 'short_name' => '78701', 'types' => ['postal_code']],
    ];
}

function gbpBackedLocation(array $overrides = []): Location
{
    $site = Site::factory()->create();
    CurrentSite::set($site->id);

    return Location::factory()->create(array_merge([
        'site_id' => $site->id,
        'name' => 'Apex Plumbing — Austin',
        'phone' => '+15125550142',
        'place_id' => 'ChIJMOCK00000000000000000000',
        'gbp_url' => 'https://maps.google.com/?cid=12345',
        'primary_category' => 'plumber',
        'address' => '500 W 2nd St, Austin, TX 78701, USA',
        'address_components' => gbpAddressComponents(),
        'hours' => ['mon' => ['open' => '08:00', 'close' => '17:00']],
    ], $overrides));
}

test('it creates a NAP profile from the location GBP data', function (): void {
    $location = gbpBackedLocation();

    $result = app(NapProfileHydrator::class)->hydrate($location);

    expect($result->created())->toBeTrue();

    $nap = LocationNapProfile::query()->withoutGlobalScope(SiteScope::class)
        ->where('location_id', $location->id)->first();

    expect($nap)->not->toBeNull()
        ->and($nap->site_id)->toBe($location->site_id)
        ->and($nap->business_name)->toBe('Apex Plumbing — Austin')
        ->and($nap->address_1)->toBe('500 West 2nd Street')
        ->and($nap->city)->toBe('Austin')
        ->and($nap->state)->toBe('TX')
        ->and($nap->postal)->toBe('78701')
        ->and($nap->phone_primary)->toBe('+15125550142')
        ->and($nap->categories)->toBe(['plumber'])
        ->and($nap->hours)->toBe(['mon' => ['open' => '08:00', 'close' => '17:00']]);
});

test('it parses a suite into address line 2', function (): void {
    $location = gbpBackedLocation([
        'address_components' => [
            ['long_name' => '500', 'types' => ['street_number']],
            ['long_name' => 'West 2nd Street', 'types' => ['route']],
            ['long_name' => 'Suite 220', 'types' => ['subpremise']],
            ['long_name' => 'Austin', 'types' => ['locality']],
            ['long_name' => 'Texas', 'short_name' => 'TX', 'types' => ['administrative_area_level_1']],
            ['long_name' => '78701', 'types' => ['postal_code']],
        ],
    ]);

    app(NapProfileHydrator::class)->hydrate($location);

    $nap = LocationNapProfile::query()->withoutGlobalScope(SiteScope::class)
        ->where('location_id', $location->id)->first();

    expect($nap->address_1)->toBe('500 West 2nd Street')
        ->and($nap->address_2)->toBe('Suite 220');
});

test('it skips creation when Google is missing a required field', function (): void {
    // No street_number / route → address_1 blank → cannot build a valid NAP.
    $location = gbpBackedLocation([
        'address_components' => [
            ['long_name' => 'Austin', 'types' => ['locality']],
            ['long_name' => 'Texas', 'short_name' => 'TX', 'types' => ['administrative_area_level_1']],
        ],
        'phone' => null,
    ]);

    $result = app(NapProfileHydrator::class)->hydrate($location);

    expect($result->skipped())->toBeTrue()
        ->and($result->missing)->toContain('address_1')
        ->and($result->missing)->toContain('postal')
        ->and($result->missing)->toContain('phone_primary')
        ->and(LocationNapProfile::query()->withoutGlobalScope(SiteScope::class)
            ->where('location_id', $location->id)->exists())->toBeFalse();
});

test('it fills only blank fields on an existing NAP and never overwrites', function (): void {
    $location = gbpBackedLocation();

    LocationNapProfile::factory()->create([
        'site_id' => $location->site_id,
        'location_id' => $location->id,
        'business_name' => 'Operator-Entered Name',   // authoritative — must survive
        'phone_primary' => '512-555-9999',            // authoritative — must survive
        'address_1' => '',                            // blank — should be filled
        'city' => 'Austin',
        'state' => 'TX',
        'postal' => '',                               // blank — should be filled
    ]);

    $result = app(NapProfileHydrator::class)->hydrate($location);

    expect($result->updated())->toBeTrue()
        ->and($result->fields)->toContain('address_1')
        ->and($result->fields)->toContain('postal')
        ->and($result->fields)->not->toContain('business_name');

    $nap = LocationNapProfile::query()->withoutGlobalScope(SiteScope::class)
        ->where('location_id', $location->id)->first();

    expect($nap->business_name)->toBe('Operator-Entered Name')
        ->and($nap->phone_primary)->toBe('512-555-9999')
        ->and($nap->address_1)->toBe('500 West 2nd Street')
        ->and($nap->postal)->toBe('78701');
});

test('it is a noop when the existing NAP is already complete', function (): void {
    $location = gbpBackedLocation();
    LocationNapProfile::factory()->create([
        'site_id' => $location->site_id,
        'location_id' => $location->id,
        'address_2' => 'Suite 1',
        'hours' => ['mon' => ['open' => '09:00', 'close' => '18:00']],
        'categories' => ['operator-chosen'],
    ]);

    $result = app(NapProfileHydrator::class)->hydrate($location);

    expect($result->changed())->toBeFalse()
        ->and($result->outcome)->toBe('noop');
});
