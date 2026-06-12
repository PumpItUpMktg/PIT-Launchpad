<?php

use App\Models\Location;
use App\Models\Site;

it('persists and casts the Places enrichment fields', function () {
    $site = Site::factory()->create();

    $location = Location::create([
        'site_id' => $site->id,
        'name' => 'Apex Plumbing — Austin',
        'place_id' => 'ChIJN1t_tDeuEmsRUsoyG83frY4',
        'address' => '500 W 2nd St, Austin, TX 78701',
        'address_components' => [
            ['long_name' => 'Austin', 'types' => ['locality']],
            ['long_name' => 'TX', 'types' => ['administrative_area_level_1']],
        ],
        'lat' => '30.2671530',
        'lng' => '-97.7430608',
        'gbp_url' => 'https://maps.google.com/?cid=123',
        'hours' => ['mon' => ['open' => '08:00', 'close' => '17:00'], 'sun' => 'closed'],
        'is_storefront' => true,
    ]);

    $fresh = $location->fresh();
    expect($fresh->place_id)->toBe('ChIJN1t_tDeuEmsRUsoyG83frY4')
        ->and($fresh->address_components)->toBeArray()
        ->and($fresh->address_components[0]['long_name'])->toBe('Austin')
        ->and((float) $fresh->lat)->toBe(30.267153)
        ->and((float) $fresh->lng)->toBe(-97.7430608)
        ->and($fresh->hours['mon']['open'])->toBe('08:00')
        ->and($fresh->hours['sun'])->toBe('closed')
        ->and($fresh->is_storefront)->toBeTrue();
});

it('belongs to its site', function () {
    $site = Site::factory()->create();
    $location = Location::factory()->create(['site_id' => $site->id]);

    expect($location->site->id)->toBe($site->id);
});
