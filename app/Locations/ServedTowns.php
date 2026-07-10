<?php

namespace App\Locations;

use App\Integrations\Census\Geocoder;
use App\Models\Location;
use App\Models\Scopes\SiteScope;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * The served-towns list on a Location — the GBP service-area towns, one page's coverage claim.
 *
 * - `normalize()` turns tag-input strings ("Montclair, NJ") into structured rows, geocoding new
 *   names via the Geocoder seam (rows whose name+state already existed keep their coordinates — no
 *   refetch on every save; an ungeocodable town stores with geocoded=false rather than blocking).
 * - `conflicts()` is the CANNIBALIZATION GUARD: a town belongs to exactly ONE location per site.
 *   Returns each offending town with the name of the location that already owns it, so the form
 *   can reject with a message that names the conflict.
 *
 * The future GBP API becomes an alternate WRITER of the same field — not a new field.
 */
final class ServedTowns
{
    public function __construct(private readonly Geocoder $geocoder) {}

    /**
     * @param  list<string>  $entries  tag-input strings, e.g. "Montclair, NJ"
     * @param  list<array<string, mixed>>  $existing  the location's current rows (coordinate reuse)
     * @return list<array{name: string, state: string, lat: float|null, lng: float|null, geocoded: bool}>
     */
    public function normalize(array $entries, array $existing = []): array
    {
        $known = [];
        foreach ($existing as $row) {
            if (trim((string) ($row['name'] ?? '')) !== '') {
                $known[$this->key((string) $row['name'], (string) ($row['state'] ?? ''))] = $row;
            }
        }

        $out = [];
        $seen = [];
        foreach ($entries as $entry) {
            [$name, $state] = $this->parse((string) $entry);
            if ($name === '') {
                continue;
            }
            $key = $this->key($name, $state);
            if (isset($seen[$key])) {
                continue; // dedupe within the input
            }
            $seen[$key] = true;

            // A town we already had keeps its stored coordinates — no refetch on every save.
            if (isset($known[$key])) {
                $out[] = [
                    'name' => $name,
                    'state' => $state,
                    'lat' => isset($known[$key]['lat']) ? (float) $known[$key]['lat'] : null,
                    'lng' => isset($known[$key]['lng']) ? (float) $known[$key]['lng'] : null,
                    'geocoded' => (bool) ($known[$key]['geocoded'] ?? false),
                ];

                continue;
            }

            $out[] = $this->geocodeTown($name, $state);
        }

        return $out;
    }

    /**
     * Towns in $towns already claimed by ANOTHER location on the same site — each with the owning
     * location's name, so the rejection message can say exactly where the conflict lives.
     *
     * @param  list<array{name: string, state: string}|string>  $towns
     * @return list<array{town: string, location: string}>
     */
    public function conflicts(string $siteId, array $towns, ?string $exceptLocationId = null): array
    {
        $wanted = [];
        foreach ($towns as $town) {
            [$name, $state] = is_array($town)
                ? [trim($town['name']), trim($town['state'])]
                : $this->parse($town);
            if ($name !== '') {
                $wanted[$this->key($name, $state)] = $state !== '' ? "{$name}, {$state}" : $name;
            }
        }
        if ($wanted === []) {
            return [];
        }

        $others = Location::withoutGlobalScope(SiteScope::class)
            ->where('site_id', $siteId)
            ->when($exceptLocationId !== null, fn ($q) => $q->whereKeyNot($exceptLocationId))
            ->get(['id', 'name', 'served_towns']);

        $out = [];
        foreach ($others as $location) {
            foreach ($location->served_towns ?? [] as $row) {
                $key = $this->key((string) ($row['name'] ?? ''), (string) ($row['state'] ?? ''));
                if (isset($wanted[$key])) {
                    $out[] = ['town' => $wanted[$key], 'location' => (string) $location->name];
                    unset($wanted[$key]); // report each town once
                }
            }
        }

        return $out;
    }

    /** @return array{name: string, state: string, lat: float|null, lng: float|null, geocoded: bool} */
    private function geocodeTown(string $name, string $state): array
    {
        $query = $state !== '' ? "{$name}, {$state}" : $name;

        try {
            $result = $this->geocoder->geocode($query);
        } catch (Throwable $e) {
            Log::warning('Served-town geocode failed — stored ungeocoded.', ['town' => $query, 'error' => $e->getMessage()]);
            $result = null;
        }

        return [
            'name' => $name,
            'state' => $state,
            'lat' => $result?->lat,
            'lng' => $result?->lng,
            'geocoded' => $result !== null,
        ];
    }

    /** "Montclair, NJ" → [Montclair, NJ]; a bare name has no state. @return array{0: string, 1: string} */
    private function parse(string $entry): array
    {
        $parts = array_map('trim', explode(',', $entry, 2));

        return [$parts[0], strtoupper($parts[1] ?? '')];
    }

    private function key(string $name, string $state): string
    {
        return mb_strtolower(trim($name)).'|'.strtoupper(trim($state));
    }
}
