<?php

namespace App\TownRank;

use App\Jobs\RecollectTownRank;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;

/**
 * The progress record of a site's queued re-read of phantom / masked town-rank points (§ Town Rank) — what
 * the board shows while {@see RecollectTownRank} works through the backlog in chunks, and the
 * guard that keeps a second click from starting a second chain while one is live. Cache-backed (one key
 * per site, kept a week): the job writes it after every scan, the page reads it on a poll.
 */
final class TownRankRecollectProgress
{
    public const STATUS_IDLE = 'idle';

    public const STATUS_RUNNING = 'running';

    public const STATUS_DONE = 'done';

    public const STATUS_FAILED = 'failed';

    /** A running record older than this with no update is a dead chain (worker restart) — a new run may start. */
    public const STALE_MINUTES = 20;

    /** @return array{status: string, total: int, read: int, ranked: int, not_found: int, skipped: int, remaining: int, started_at: ?string, updated_at: ?string, finished_at: ?string, error: ?string} */
    public static function get(string $siteId): array
    {
        $raw = Cache::get(self::key($siteId));

        return is_array($raw) ? array_merge(self::blank(), $raw) : self::blank();
    }

    public static function start(string $siteId, int $total): void
    {
        $now = Carbon::now()->toDateTimeString();
        self::put($siteId, array_merge(self::blank(), [
            'status' => self::STATUS_RUNNING, 'total' => $total, 'remaining' => $total, 'started_at' => $now, 'updated_at' => $now,
        ]));
    }

    /** The first chunk's count of the backlog — the run's total and remaining. */
    public static function size(string $siteId, int $total): void
    {
        self::put($siteId, array_merge(self::get($siteId), ['status' => self::STATUS_RUNNING, 'total' => $total, 'remaining' => $total, 'updated_at' => Carbon::now()->toDateTimeString()]));
    }

    /** Add one chunk's outcome and the live remaining count. */
    public static function advance(string $siteId, int $read, int $ranked, int $notFound, int $skipped, int $remaining): void
    {
        $p = self::get($siteId);
        self::put($siteId, array_merge($p, [
            'status' => self::STATUS_RUNNING,
            'read' => $p['read'] + $read, 'ranked' => $p['ranked'] + $ranked, 'not_found' => $p['not_found'] + $notFound, 'skipped' => $p['skipped'] + $skipped,
            'remaining' => max(0, $remaining), 'updated_at' => Carbon::now()->toDateTimeString(),
        ]));
    }

    public static function finish(string $siteId): void
    {
        $now = Carbon::now()->toDateTimeString();
        self::put($siteId, array_merge(self::get($siteId), ['status' => self::STATUS_DONE, 'remaining' => 0, 'updated_at' => $now, 'finished_at' => $now]));
    }

    public static function fail(string $siteId, string $error): void
    {
        $now = Carbon::now()->toDateTimeString();
        self::put($siteId, array_merge(self::get($siteId), ['status' => self::STATUS_FAILED, 'error' => mb_strimwidth($error, 0, 300, '…'), 'updated_at' => $now, 'finished_at' => $now]));
    }

    /** A chain is live when it is running and has reported within the stale window. */
    public static function isRunning(string $siteId): bool
    {
        $p = self::get($siteId);
        if ($p['status'] !== self::STATUS_RUNNING || $p['updated_at'] === null) {
            return false;
        }

        return Carbon::parse($p['updated_at'])->gt(Carbon::now()->subMinutes(self::STALE_MINUTES));
    }

    public static function clear(string $siteId): void
    {
        Cache::forget(self::key($siteId));
    }

    /** @return array{status: string, total: int, read: int, ranked: int, not_found: int, skipped: int, remaining: int, started_at: ?string, updated_at: ?string, finished_at: ?string, error: ?string} */
    private static function blank(): array
    {
        return ['status' => self::STATUS_IDLE, 'total' => 0, 'read' => 0, 'ranked' => 0, 'not_found' => 0, 'skipped' => 0, 'remaining' => 0, 'started_at' => null, 'updated_at' => null, 'finished_at' => null, 'error' => null];
    }

    /** @param  array<string, mixed>  $value */
    private static function put(string $siteId, array $value): void
    {
        Cache::put(self::key($siteId), $value, Carbon::now()->addDays(7));
    }

    private static function key(string $siteId): string
    {
        return 'town_rank.recollect.'.$siteId;
    }
}
