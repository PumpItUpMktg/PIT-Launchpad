<?php

namespace App\Integrations\Places;

/**
 * Deterministic Places stand-in for tests + local dev without a key: one canned
 * candidate that resolves to a fully-populated PlaceDetails. No network.
 */
final class MockPlacesProvider implements PlacesProvider
{
    public const PLACE_ID = 'ChIJMOCK00000000000000000000';

    public function search(string $query): array
    {
        if (trim($query) === '') {
            return [];
        }

        return [
            new PlaceCandidate(self::PLACE_ID, 'Apex Plumbing — Austin', '500 W 2nd St, Austin, TX 78701'),
        ];
    }

    public function details(string $placeId): ?PlaceDetails
    {
        if ($placeId !== self::PLACE_ID) {
            return null;
        }

        return new PlaceDetails(
            placeId: self::PLACE_ID,
            name: 'Apex Plumbing — Austin',
            address: '500 W 2nd St, Austin, TX 78701, USA',
            addressComponents: [
                ['long_name' => '500', 'short_name' => '500', 'types' => ['street_number']],
                ['long_name' => 'West 2nd Street', 'short_name' => 'W 2nd St', 'types' => ['route']],
                ['long_name' => 'Austin', 'short_name' => 'Austin', 'types' => ['locality']],
                ['long_name' => 'Texas', 'short_name' => 'TX', 'types' => ['administrative_area_level_1']],
                ['long_name' => '78701', 'short_name' => '78701', 'types' => ['postal_code']],
            ],
            phone: '+15125550142',
            hours: [
                'mon' => ['open' => '08:00', 'close' => '17:00'],
                'tue' => ['open' => '08:00', 'close' => '17:00'],
                'wed' => ['open' => '08:00', 'close' => '17:00'],
                'thu' => ['open' => '08:00', 'close' => '17:00'],
                'fri' => ['open' => '08:00', 'close' => '17:00'],
                'sat' => 'closed',
                'sun' => 'closed',
            ],
            lat: 30.2671530,
            lng: -97.7430608,
            gbpUrl: 'https://maps.google.com/?cid=12345678901234567890',
            website: 'https://apexplumbing.example',
        );
    }

    public function smokeTest(): PlacesStatus
    {
        return PlacesStatus::ok();
    }
}
