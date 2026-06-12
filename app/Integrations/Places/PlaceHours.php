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

        // Open 24/7: Google returns a SINGLE period with open day 0, time 0000 and
        // NO close. Every day is 24h — never 00:00–23:59.
        if (self::isAlwaysOpen($periods)) {
            return array_fill_keys(self::ORDER, '24h');
        }

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

            // A period with no close = that day is open 24 hours (distinct from a
            // real open/close pair) — map it, don't fabricate a 23:59 close.
            if (! is_array($close) || ! isset($close['time'])) {
                $hours[$dayKey] = '24h';
            } else {
                $hours[$dayKey] = ['open' => self::time((string) $open['time']), 'close' => self::time((string) $close['time'])];
            }
        }

        $ordered = [];
        foreach (self::ORDER as $day) {
            $ordered[$day] = $hours[$day] ?? 'closed';
        }

        return $ordered;
    }

    /**
     * @param  array<int, mixed>  $periods
     */
    private static function isAlwaysOpen(array $periods): bool
    {
        if (count($periods) !== 1) {
            return false;
        }

        $period = reset($periods);
        $open = is_array($period) ? ($period['open'] ?? null) : null;

        return is_array($open)
            && ($open['day'] ?? null) === 0
            && ($open['time'] ?? null) === '0000'
            && empty($period['close']);
    }

    private static function time(string $hhmm): string
    {
        $hhmm = str_pad(substr($hhmm, 0, 4), 4, '0', STR_PAD_LEFT);

        return substr($hhmm, 0, 2).':'.substr($hhmm, 2, 2);
    }
}
