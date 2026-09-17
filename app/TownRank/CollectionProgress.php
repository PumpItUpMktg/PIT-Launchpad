<?php

namespace App\TownRank;

/**
 * "How far along is this scan, and how long is left?" — the honest answer to the question a bare
 * "collecting" leaves hanging.
 *
 * The estimate is arithmetic, not a guess: every town left is one free DataForSEO task_get, and reads run
 * under a known ceiling ({@see config('services.dataforseo.read_rate_limit_per_min')}, 600/min), so the
 * floor is remaining ÷ rate. It is deliberately a FLOOR and says "at least": the collector shares its lane,
 * runs on a schedule rather than continuously, and a task not yet ready waits for the next pass. Quoting a
 * best case as if it were a promise is how a progress bar starts lying.
 */
final class CollectionProgress
{
    /**
     * @return array{collected: int, points: int, remaining: int, eta_seconds: int|null, eta: string|null}
     */
    public static function for(int $collected, int $points): array
    {
        $remaining = max(0, $points - $collected);
        $perMinute = max(1, (int) config('services.dataforseo.read_rate_limit_per_min', 600));
        $seconds = $remaining > 0 ? (int) ceil($remaining / $perMinute * 60) : 0;

        return [
            'collected' => $collected,
            'points' => $points,
            'remaining' => $remaining,
            'eta_seconds' => $remaining > 0 ? $seconds : null,
            'eta' => $remaining > 0 ? self::label($seconds) : null,
        ];
    }

    /** "under a minute" / "about 4 minutes" / "about 1h 20m" — coarse on purpose; nobody reads seconds. */
    public static function label(int $seconds): string
    {
        if ($seconds < 60) {
            return 'under a minute';
        }
        $minutes = (int) round($seconds / 60);
        if ($minutes < 60) {
            return "about {$minutes} minute".($minutes === 1 ? '' : 's');
        }
        $hours = intdiv($minutes, 60);
        $rest = $minutes % 60;

        return 'about '.$hours.'h'.($rest > 0 ? ' '.$rest.'m' : '');
    }
}
