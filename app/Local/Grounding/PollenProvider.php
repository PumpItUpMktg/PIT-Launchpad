<?php

namespace App\Local\Grounding;

use App\Models\Location;
use Illuminate\Support\Facades\Http;

/**
 * The pollen SEASONS for the location point, from Google's Pollen API.
 *
 * The forecast itself is useless as grounding — a page that says "grass pollen is high today" is wrong
 * tomorrow and wrong for the next year. What IS durable is the per-plant `plantDescription.season`
 * Google returns alongside it: which plants grow here and when their season runs. That is a fact about
 * the place, not the week, and it is what an HVAC or mold page can honestly build on.
 *
 * So one day of forecast is requested, the plants are kept and the index values are thrown away.
 *
 * Needs the Maps Platform key with the Pollen API enabled; degrades to no facts without it.
 */
final class PollenProvider implements GroundingProvider
{
    private const URL = 'https://pollen.googleapis.com/v1/forecast:lookup';

    public function fetch(Location $location): array
    {
        $source = 'google pollen';
        $key = (string) config('services.google.maps_api_key', '');
        $lat = $location->latitude ?? $location->lat;
        $lng = $location->longitude ?? $location->lng;
        if ($key === '' || $lat === null || $lng === null) {
            return ['facts' => [], 'source' => $source];
        }

        $response = Http::timeout(15)->get(self::URL, [
            'key' => $key,
            'location.latitude' => (float) $lat,
            'location.longitude' => (float) $lng,
            'days' => 1,
            'languageCode' => 'en',
            'plantsDescription' => true,
        ]);
        if (! $response->successful()) {
            return ['facts' => [], 'source' => $source];
        }

        $daily = $response->json('dailyInfo');
        $day = is_array($daily) ? ($daily[0] ?? null) : null;
        if (! is_array($day)) {
            return ['facts' => [], 'source' => $source];
        }

        // Plants Google knows grow here, with the season it publishes for each.
        $seasons = [];
        foreach ((array) ($day['plantInfo'] ?? []) as $plant) {
            if (! is_array($plant)) {
                continue;
            }
            $name = trim((string) ($plant['displayName'] ?? ''));
            $description = is_array($plant['plantDescription'] ?? null) ? $plant['plantDescription'] : [];
            $season = trim((string) ($description['season'] ?? ''));
            if ($name !== '' && $season !== '') {
                $seasons[$name] = mb_strtolower($season);
            }
        }
        if ($seasons === []) {
            return ['facts' => [], 'source' => $source];
        }

        $facts = [];
        foreach (array_slice($seasons, 0, 4, true) as $name => $season) {
            $facts[] = sprintf('%s pollen is seasonal here: %s.', $name, $season);
        }

        // Which pollen types the area carries at all — the durable half of the type list.
        $types = [];
        foreach ((array) ($day['pollenTypeInfo'] ?? []) as $type) {
            $name = is_array($type) ? trim((string) ($type['displayName'] ?? '')) : '';
            if ($name !== '') {
                $types[] = mb_strtolower($name);
            }
        }
        if (count($types) > 1) {
            $facts[] = sprintf('The pollen types tracked for this area are %s.', implode(', ', $types));
        }

        return ['facts' => $facts, 'source' => $source];
    }
}
