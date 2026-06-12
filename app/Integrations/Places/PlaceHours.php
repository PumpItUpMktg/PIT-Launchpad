<?php

namespace App\Integrations\Places;

/**
 * Maps a Google Places `opening_hours.periods` array into the stored per-day
 * shape (`{"mon": {"open","close"}, "sun": "closed", …}`). Google numbers days
 * 0=Sunday..6=Saturday and times as "HHMM"; missing days are closed.
 */
final class PlaceHours
{
    private const DAY_KEYS = [0 => 'sun', 1 => 'mon', 2 => 'tue', 3 => 'wed', 4 => 'thu', 5 => 'fri', 6 => 'sat'];

    private const ORDER = ['mon', 'tue', 'wed', 'thu', 'fri', 'sat', 'sun'];

    /**
     * @param  array<string, mixed>|null  $openingHours
     * @return array<string, mixed>
     */
    public static function fromGoogle(?array $openingHours): array
    {
        $periods = is_array($openingHours['periods'] ?? null) ? $openingHours['periods'] : [];

        $hours = [];
        foreach ($periods as $period) {
            if (! is_array($period)) {
                continue;
            }

            $open = $period['open'] ?? null;
            if (! is_array($open) || ! isset($open['day'], $open['time'])) {
                continue;
            }

            $dayKey = self::DAY_KEYS[$open['day']] ?? null;
            if ($dayKey === null) {
                continue;
            }

            $close = $period['close'] ?? null;
            $closeTime = is_array($close) && isset($close['time']) ? self::time((string) $close['time']) : '23:59';

            $hours[$dayKey] = ['open' => self::time((string) $open['time']), 'close' => $closeTime];
        }

        $ordered = [];
        foreach (self::ORDER as $day) {
            $ordered[$day] = $hours[$day] ?? 'closed';
        }

        return $ordered;
    }

    private static function time(string $hhmm): string
    {
        $hhmm = str_pad(substr($hhmm, 0, 4), 4, '0', STR_PAD_LEFT);

        return substr($hhmm, 0, 2).':'.substr($hhmm, 2, 2);
    }
}
