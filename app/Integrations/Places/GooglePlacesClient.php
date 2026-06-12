<?php

namespace App\Integrations\Places;

use Illuminate\Http\Client\Factory as Http;

/**
 * Real Google Places adapter. A pasted Maps/GBP URL or a typed business name both
 * route through Text Search → candidates; Place Details fills the form. Never
 * silent-saves — the operator confirms. A REQUEST_DENIED is surfaced as the
 * Places-API-not-enabled signal the smoke-test and import action present.
 */
final class GooglePlacesClient implements PlacesProvider
{
    private const BASE = 'https://maps.googleapis.com/maps/api/place';

    private const DETAIL_FIELDS = 'place_id,name,formatted_address,address_components,international_phone_number,opening_hours,geometry,url,website';

    public function __construct(
        private readonly Http $http,
        private readonly string $apiKey,
        private readonly int $timeout = 15,
    ) {}

    public function search(string $query): array
    {
        $query = PlaceQuery::normalize($query);
        if ($query === '') {
            return [];
        }

        $response = $this->get('/textsearch/json', ['query' => $query]);
        $results = is_array($response['results'] ?? null) ? $response['results'] : [];

        $candidates = [];
        foreach ($results as $result) {
            if (! is_array($result) || empty($result['place_id'])) {
                continue;
            }
            $candidates[] = new PlaceCandidate(
                (string) $result['place_id'],
                (string) ($result['name'] ?? ''),
                (string) ($result['formatted_address'] ?? ''),
            );
        }

        return $candidates;
    }

    public function details(string $placeId): ?PlaceDetails
    {
        if ($placeId === '') {
            return null;
        }

        $response = $this->get('/details/json', ['place_id' => $placeId, 'fields' => self::DETAIL_FIELDS]);
        $r = is_array($response['result'] ?? null) ? $response['result'] : null;
        if ($r === null) {
            return null;
        }

        $location = $r['geometry']['location'] ?? [];

        return new PlaceDetails(
            placeId: (string) ($r['place_id'] ?? $placeId),
            name: (string) ($r['name'] ?? ''),
            address: (string) ($r['formatted_address'] ?? ''),
            addressComponents: is_array($r['address_components'] ?? null) ? $r['address_components'] : [],
            phone: isset($r['international_phone_number']) ? (string) $r['international_phone_number'] : null,
            hours: PlaceHours::fromGoogle(is_array($r['opening_hours'] ?? null) ? $r['opening_hours'] : null),
            lat: isset($location['lat']) ? (float) $location['lat'] : null,
            lng: isset($location['lng']) ? (float) $location['lng'] : null,
            gbpUrl: isset($r['url']) ? (string) $r['url'] : null,
            website: isset($r['website']) ? (string) $r['website'] : null,
        );
    }

    public function smokeTest(): PlacesStatus
    {
        if ($this->apiKey === '') {
            return PlacesStatus::failed('GOOGLE_MAPS_API_KEY is not set on the engine.');
        }

        $response = $this->get('/findplacefromtext/json', [
            'input' => 'coffee',
            'inputtype' => 'textquery',
            'fields' => 'place_id',
        ]);

        $status = (string) ($response['status'] ?? 'UNKNOWN');

        return match ($status) {
            'OK', 'ZERO_RESULTS' => PlacesStatus::ok(),
            'REQUEST_DENIED' => PlacesStatus::failed('Places API request denied — enable the Places API on the GCP project and check the key restrictions. ('.($response['error_message'] ?? '').')'),
            default => PlacesStatus::failed('Places API returned status '.$status.'. '.($response['error_message'] ?? '')),
        };
    }

    /**
     * @param  array<string, mixed>  $params
     * @return array<string, mixed>
     */
    private function get(string $path, array $params): array
    {
        $response = $this->http
            ->timeout($this->timeout)
            ->get(self::BASE.$path, array_merge($params, ['key' => $this->apiKey]));

        $json = $response->json();

        return is_array($json) ? $json : ['status' => 'HTTP_'.$response->status()];
    }
}
