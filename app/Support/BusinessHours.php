<?php

namespace App\Support;

/**
 * Converts business hours between the stored per-day map
 * (`{"mon": {"open","close"}, "sun": "closed", "sat": "24h", …}`) and the flat,
 * day-named form fields the resource edits (`hours_mon_state`/`_open`/`_close`).
 * `toFields`/`fromFields` are the form round-trip; `normalize` repairs any legacy
 * persisted shape (incl. the numeric-keyed rows the old repeater wrote) back to
 * the day-keyed map. Kept here — pure and tested — so the resource form stays a
 * thin shell over it. The row-shaped helpers (`fromStored`/`toStored`/
 * `sameEveryDay`/`alwaysOpen`) back the deferred "same every day" / "always open"
 * shortcuts.
 */
final class BusinessHours
{
    /** @var list<string> */
    public const DAYS = ['mon', 'tue', 'wed', 'thu', 'fri', 'sat', 'sun'];

    /**
     * Stored map → exactly 7 ordered repeater rows (missing/closed days fill as
     * closed), so the form always renders every day.
     *
     * @param  array<string, mixed>|null  $hours
     * @return list<array{day: string, closed: bool, all_day: bool, open: string|null, close: string|null}>
     */
    public static function fromStored(?array $hours): array
    {
        $hours = self::normalize($hours);
        $rows = [];

        foreach (self::DAYS as $day) {
            $value = $hours[$day] ?? 'closed';
            $allDay = $value === '24h';
            $open = is_array($value) ? ($value['open'] ?? null) : null;
            $closed = ! is_array($value) && ! $allDay;

            $rows[] = [
                'day' => $day,
                'closed' => $closed,
                'all_day' => $allDay,
                'open' => $open,
                'close' => is_array($value) ? ($value['close'] ?? null) : null,
            ];
        }

        return $rows;
    }

    /** @var array<string, string> */
    private const DAY_NAMES = [
        'mon' => 'Monday', 'tue' => 'Tuesday', 'wed' => 'Wednesday', 'thu' => 'Thursday',
        'fri' => 'Friday', 'sat' => 'Saturday', 'sun' => 'Sunday',
    ];

    /**
     * Stored hours → the CUSTOMER-FACING display rows: 12-hour am/pm times, full day names, and
     * consecutive days with identical hours COLLAPSED into a range ("Monday – Saturday, 8am – 6pm").
     * Closed days break a run and are dropped (never a wall of "Closed"); a closed gap therefore
     * splits ranges honestly (Mon–Tue, Thu–Sat when Wednesday is closed). Display only — structured
     * data (LocalBusiness openingHoursSpecification) stays per-day, so the collapse costs no SEO.
     *
     * @param  array<string, mixed>|null  $hours
     * @return list<array{label: string, value: string}>
     */
    public static function displayRows(?array $hours): array
    {
        // Each day's display value in order; null = closed/uncaptured (breaks a run, never shown).
        $values = [];
        foreach (self::fromStored($hours) as $row) {
            if ($row['all_day']) {
                $values[$row['day']] = 'Open 24 hours';
            } elseif (! $row['closed'] && trim((string) $row['open']) !== '' && trim((string) $row['close']) !== '') {
                $values[$row['day']] = self::to12h((string) $row['open']).' – '.self::to12h((string) $row['close']);
            } else {
                $values[$row['day']] = null;
            }
        }

        $rows = [];
        /** @var array{start: string, end: string, value: string}|null $run */
        $run = null;
        foreach (self::DAYS as $day) {
            $value = $values[$day];
            if ($value !== null && $run !== null && $run['value'] === $value) {
                $run['end'] = $day; // extend the run

                continue;
            }
            if ($run !== null) {
                $rows[] = self::runRow($run);
            }
            $run = $value !== null ? ['start' => $day, 'end' => $day, 'value' => $value] : null;
        }
        if ($run !== null) {
            $rows[] = self::runRow($run);
        }

        return $rows;
    }

    /**
     * @param  array{start: string, end: string, value: string}  $run
     * @return array{label: string, value: string}
     */
    private static function runRow(array $run): array
    {
        $label = $run['start'] === $run['end']
            ? self::DAY_NAMES[$run['start']]
            : self::DAY_NAMES[$run['start']].' – '.self::DAY_NAMES[$run['end']];

        return ['label' => $label, 'value' => $run['value']];
    }

    /** "08:00" → "8am", "08:30" → "8:30am", "17:00" → "5pm", "12:00" → "12pm", "00:00" → "12am". */
    private static function to12h(string $time): string
    {
        if (preg_match('/^(\d{1,2}):(\d{2})/', trim($time), $m) !== 1) {
            return trim($time); // unparseable — show as captured, never invent
        }

        $hour = (int) $m[1];
        $minutes = $m[2];
        $suffix = $hour >= 12 ? 'pm' : 'am';
        $hour12 = $hour % 12 === 0 ? 12 : $hour % 12;

        return $hour12.((int) $minutes > 0 ? ':'.$minutes : '').$suffix;
    }

    /**
     * Coerce ANY persisted shape back to the day-keyed map, repairing the legacy
     * numeric-keyed rows the Filament repeater wrote before the round-trip was
     * pinned (`[0 => "24h", …]` — positional in Mon..Sun order). Also tolerates a
     * list of `{day, …}` repeater rows. The day-keyed map passes through cleaned.
     *
     * @param  array<mixed, mixed>|null  $stored
     * @return array<string, mixed>
     */
    public static function normalize(?array $stored): array
    {
        $stored ??= [];

        // Already day-keyed (the spec shape).
        foreach ($stored as $key => $value) {
            if (in_array($key, self::DAYS, true)) {
                $out = [];
                foreach (self::DAYS as $day) {
                    if (array_key_exists($day, $stored)) {
                        $out[$day] = self::cleanValue($stored[$day]);
                    }
                }

                return $out;
            }
        }

        // A list of repeater rows ({day, closed, all_day, open, close}).
        foreach ($stored as $value) {
            if (is_array($value) && isset($value['day'])) {
                return self::toStored(array_values($stored));
            }
        }

        // Legacy numeric list of values, positional Mon..Sun.
        $values = array_values($stored);
        $out = [];
        foreach (self::DAYS as $index => $day) {
            if (array_key_exists($index, $values)) {
                $out[$day] = self::cleanValue($values[$index]);
            }
        }

        return $out;
    }

    /**
     * Stored map → flat per-day form fields (`hours_mon_state` ∈ open|closed|24h,
     * `hours_mon_open`, `hours_mon_close`). Flat + day-named, so there is no
     * repeater re-indexing to lose the keys.
     *
     * @param  array<mixed, mixed>|null  $stored
     * @return array<string, mixed>
     */
    public static function toFields(?array $stored): array
    {
        $map = self::normalize($stored);
        $fields = [];

        foreach (self::DAYS as $day) {
            $value = $map[$day] ?? 'closed';

            if ($value === '24h') {
                $fields["hours_{$day}_state"] = '24h';
                $fields["hours_{$day}_open"] = null;
                $fields["hours_{$day}_close"] = null;
            } elseif (is_array($value)) {
                $fields["hours_{$day}_state"] = 'open';
                $fields["hours_{$day}_open"] = $value['open'] ?? null;
                $fields["hours_{$day}_close"] = $value['close'] ?? null;
            } else {
                $fields["hours_{$day}_state"] = 'closed';
                $fields["hours_{$day}_open"] = null;
                $fields["hours_{$day}_close"] = null;
            }
        }

        return $fields;
    }

    /**
     * Flat per-day form fields → the stored day-keyed map.
     *
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    public static function fromFields(array $data): array
    {
        $out = [];

        foreach (self::DAYS as $day) {
            $state = $data["hours_{$day}_state"] ?? 'closed';

            if ($state === '24h') {
                $out[$day] = '24h';
            } elseif ($state === 'open' && ! empty($data["hours_{$day}_open"])) {
                $out[$day] = ['open' => (string) $data["hours_{$day}_open"], 'close' => (string) ($data["hours_{$day}_close"] ?? '')];
            } else {
                $out[$day] = 'closed';
            }
        }

        return $out;
    }

    private static function cleanValue(mixed $value): string|array
    {
        if ($value === '24h') {
            return '24h';
        }
        if (is_array($value) && ! empty($value['open'])) {
            return ['open' => (string) $value['open'], 'close' => (string) ($value['close'] ?? '')];
        }

        return 'closed';
    }

    /**
     * Repeater rows → stored map. A row is `closed` when its toggle is set or it
     * has no open time; otherwise `{open, close}`.
     *
     * @param  array<int, array<string, mixed>>|null  $rows
     * @return array<string, mixed>
     */
    public static function toStored(?array $rows): array
    {
        $rows ??= [];
        $out = [];

        foreach ($rows as $row) {
            $day = $row['day'] ?? null;
            if (! in_array($day, self::DAYS, true)) {
                continue;
            }

            if (! empty($row['closed'])) {
                $out[$day] = 'closed';
            } elseif (! empty($row['all_day'])) {
                $out[$day] = '24h';
            } elseif (empty($row['open'])) {
                $out[$day] = 'closed';
            } else {
                $out[$day] = ['open' => (string) $row['open'], 'close' => (string) ($row['close'] ?? '')];
            }
        }

        return $out;
    }

    /**
     * "Same every day" shortcut: apply the first non-closed row's open/close to
     * every day (closed days stay closed).
     *
     * @param  array<int, array<string, mixed>>|null  $rows
     * @return list<array{day: string, closed: bool, all_day: bool, open: string|null, close: string|null}>
     */
    public static function sameEveryDay(?array $rows): array
    {
        $rows ??= [];
        $template = null;
        foreach ($rows as $row) {
            if (empty($row['closed']) && ! empty($row['open'])) {
                $template = ['open' => $row['open'], 'close' => $row['close'] ?? null];
                break;
            }
        }

        if ($template === null) {
            return self::fromStored([]);
        }

        return array_map(fn (string $day): array => [
            'day' => $day,
            'closed' => false,
            'all_day' => false,
            'open' => $template['open'],
            'close' => $template['close'],
        ], self::DAYS);
    }

    /**
     * "Always open" shortcut: every day open 24 hours.
     *
     * @return list<array{day: string, closed: bool, all_day: bool, open: string|null, close: string|null}>
     */
    public static function alwaysOpen(): array
    {
        return array_map(fn (string $day): array => [
            'day' => $day,
            'closed' => false,
            'all_day' => true,
            'open' => null,
            'close' => null,
        ], self::DAYS);
    }
}
