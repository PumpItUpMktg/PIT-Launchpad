<?php

namespace App\Activity;

use Illuminate\Support\Carbon;

/**
 * The window an activity report covers: the last N days, or one calendar month. `start` / `end` are the
 * inclusive day bounds; `previousStart` / `previousEnd` the window of the same length before it, for the
 * before-and-after metrics.
 */
final class ActivityPeriod
{
    private function __construct(
        public readonly Carbon $start,
        public readonly Carbon $end,
        public readonly string $key,
        public readonly string $label,
        public readonly bool $isMonth,
    ) {}

    public static function lastDays(int $days = 30, ?Carbon $today = null): self
    {
        $end = ($today ?? Carbon::now())->copy()->endOfDay();
        $start = $end->copy()->subDays($days - 1)->startOfDay();

        return new self($start, $end, "{$days}d", "Last {$days} days", false);
    }

    /** A calendar month from its 'Y-m' key. */
    public static function month(string $key): self
    {
        $start = Carbon::createFromFormat('!Y-m', $key) ?: Carbon::now();
        $start = $start->startOfMonth();

        return new self($start, $start->copy()->endOfMonth(), $start->format('Y-m'), $start->format('F Y'), true);
    }

    /** Parse a period key ('30d', '90d' or 'Y-m'); an unknown key is the last 30 days. */
    public static function fromKey(?string $key, ?Carbon $today = null): self
    {
        if (is_string($key) && preg_match('/^(\d{1,3})d$/', $key, $m) === 1) {
            return self::lastDays(max(1, (int) $m[1]), $today);
        }
        if (is_string($key) && preg_match('/^\d{4}-\d{2}$/', $key) === 1) {
            return self::month($key);
        }

        return self::lastDays(30, $today);
    }

    public function previousStart(): Carbon
    {
        return $this->isMonth ? $this->start->copy()->subMonthNoOverflow()->startOfMonth() : $this->start->copy()->subDays($this->days());
    }

    public function previousEnd(): Carbon
    {
        return $this->start->copy()->subDay()->endOfDay();
    }

    public function days(): int
    {
        return (int) $this->start->diffInDays($this->end) + 1;
    }

    /** Whether the window is entirely in the past (a month that can be frozen). */
    public function isClosed(?Carbon $today = null): bool
    {
        return $this->end->lt(($today ?? Carbon::now())->copy()->startOfDay());
    }
}
