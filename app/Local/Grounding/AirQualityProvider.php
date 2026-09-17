<?php

namespace App\Local\Grounding;

use App\Models\Location;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Http;

/**
 * Air quality at the location point, from Google's Air Quality API — as a DATED OBSERVATION.
 *
 * There is no normals endpoint here: the API serves current conditions, a short forecast and recent
 * history, all of which are about a moment. "Air quality here is good" written from today's reading is a
 * claim the page keeps making long after the reading expires.
 *
 * So the fact is stamped with the window it describes — an average over the past week, with the date it
 * was taken. A dated observation stays true forever; an undated one rots. The drafter can use it as
 * context ("in the week to 12 September, the index averaged 44") and cannot turn it into a standing claim
 * about the air today.
 *
 * Hourly history is requested and averaged rather than reading a single hour, because one hour is weather,
 * not a characteristic of the place. Needs the Maps Platform key with the Air Quality API enabled.
 */
final class AirQualityProvider implements GroundingProvider
{
    private const HISTORY_URL = 'https://airquality.googleapis.com/v1/history:lookup';

    private const HOURS = 168;   // one week

    public function fetch(Location $location): array
    {
        $source = 'google air quality';
        $key = (string) config('services.google.maps_api_key', '');
        $lat = $location->latitude ?? $location->lat;
        $lng = $location->longitude ?? $location->lng;
        if ($key === '' || $lat === null || $lng === null) {
            return ['facts' => [], 'source' => $source];
        }

        $response = Http::timeout(20)->post(self::HISTORY_URL.'?key='.urlencode($key), [
            'location' => ['latitude' => (float) $lat, 'longitude' => (float) $lng],
            'hours' => self::HOURS,
            'pageSize' => self::HOURS,
            'languageCode' => 'en',
        ]);
        if (! $response->successful()) {
            return ['facts' => [], 'source' => $source];
        }

        $hours = $response->json('hoursInfo');
        if (! is_array($hours) || $hours === []) {
            return ['facts' => [], 'source' => $source];
        }

        $values = [];
        $dominant = [];
        $indexName = 'air-quality index';
        foreach ($hours as $hour) {
            foreach ((array) (is_array($hour) ? ($hour['indexes'] ?? []) : []) as $index) {
                if (! is_array($index) || ! isset($index['aqi'])) {
                    continue;
                }
                $values[] = (int) $index['aqi'];
                $name = trim((string) ($index['displayName'] ?? ''));
                if ($name !== '') {
                    $indexName = mb_strtolower($name);
                }
                $pollutant = trim((string) ($index['dominantPollutant'] ?? ''));
                if ($pollutant !== '') {
                    $dominant[$pollutant] = ($dominant[$pollutant] ?? 0) + 1;
                }
                break;   // one index per hour — the first is the region's own
            }
        }
        if ($values === []) {
            return ['facts' => [], 'source' => $source];
        }

        $average = (int) round(array_sum($values) / count($values));
        $facts = [sprintf(
            'In the week to %s, the %s at this location averaged %d (hourly readings, Google Air Quality).',
            Carbon::now()->toFormattedDateString(),
            $indexName,
            $average,
        )];

        arsort($dominant);
        $worst = (string) array_key_first($dominant);
        if ($dominant !== []) {
            $facts[] = sprintf('Over that week the dominant pollutant was most often %s.', strtoupper($worst));
        }

        return ['facts' => $facts, 'source' => $source];
    }
}
