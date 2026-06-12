<?php

namespace App\Support;

/**
 * Converts business hours between the stored per-day map
 * (`{"mon": {"open","close"}, "sun": "closed", …}`) and the flat 7-row list the
 * Filament repeater edits (`[{day, closed, open, close}, …]`). Kept here, pure and
 * tested, so the resource form stays a thin shell over it.
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
     * @return list<array{day: string, closed: bool, open: string|null, close: string|null}>
     */
    public static function fromStored(?array $hours): array
    {
        $hours ??= [];
        $rows = [];

        foreach (self::DAYS as $day) {
            $value = $hours[$day] ?? 'closed';
            $closed = ! is_array($value);

            $rows[] = [
                'day' => $day,
                'closed' => $closed,
                'open' => $closed ? null : ($value['open'] ?? null),
                'close' => $closed ? null : ($value['close'] ?? null),
            ];
        }

        return $rows;
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

            if (! empty($row['closed']) || empty($row['open'])) {
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
     * @return list<array{day: string, closed: bool, open: string|null, close: string|null}>
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
            'open' => $template['open'],
            'close' => $template['close'],
        ], self::DAYS);
    }
}
