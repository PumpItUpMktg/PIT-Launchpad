<?php

namespace App\Operate;

use Illuminate\Support\Facades\DB;

/**
 * The "is the background worker actually draining?" signal — read straight off the database queue
 * tables (this app runs the `database` queue driver). A publish is asynchronous: Repush/Approve
 * enqueue a job and the worker publishes it. When the worker is down, jobs pile up in `jobs` and
 * approved pages never leave "ready to publish" — so a growing, AGEING backlog (or failed jobs) is the
 * tell. Surfaced as an operator banner with the drain escape hatch; never blocks anything.
 */
final class QueueHealth
{
    /**
     * @return array{pending: int, oldest_minutes: int, failed: int, stalled: bool}
     */
    public function snapshot(int $stalledAfterMinutes = 5): array
    {
        $pending = (int) DB::table('jobs')->count();
        $oldestAvailableAt = DB::table('jobs')->min('available_at');
        $oldestMinutes = $oldestAvailableAt !== null
            ? (int) floor(max(0, time() - (int) $oldestAvailableAt) / 60)
            : 0;
        $failed = (int) DB::table('failed_jobs')->count();

        return [
            'pending' => $pending,
            'oldest_minutes' => $oldestMinutes,
            'failed' => $failed,
            // Stalled = a backlog that has sat past the threshold (the worker isn't picking it up), or
            // any failed job. A small, fresh backlog is normal (the worker is mid-drain) → not stalled.
            'stalled' => ($pending > 0 && $oldestMinutes >= $stalledAfterMinutes) || $failed > 0,
        ];
    }

    /**
     * The failed jobs grouped by their cause — job class ✕ first exception line, most-frequent first —
     * so the banner can show WHAT failed and WHY, not just a count. Mirrors launchpad:queue-diagnose.
     *
     * @return list<array{job: string, reason: string, count: int, last: string}>
     */
    public function failures(int $limit = 8): array
    {
        return DB::table('failed_jobs')
            ->orderByDesc('failed_at')
            ->get()
            ->groupBy(fn (object $row): string => $this->jobClass($row->payload).'  ✕  '.$this->exceptionHead($row->exception))
            ->map(fn ($rows): array => [
                'job' => $this->jobClass($rows->first()->payload),
                'reason' => $this->exceptionHead($rows->first()->exception),
                'count' => $rows->count(),
                'last' => (string) $rows->max('failed_at'),
            ])
            ->sortByDesc('count')
            ->take($limit)
            ->values()
            ->all();
    }

    /** Delete every failed_jobs row (the operator "Clear failed" action / queue:flush). Returns the count. */
    public function clearFailed(): int
    {
        return DB::table('failed_jobs')->delete();
    }

    /** The job class from a serialized queue payload (e.g. "App\\Jobs\\PublishContent"), basename only. */
    private function jobClass(?string $payload): string
    {
        $data = json_decode((string) $payload, true);
        $name = is_array($data) && isset($data['displayName']) ? (string) $data['displayName'] : 'unknown job';

        return ($pos = strrpos($name, '\\')) !== false ? substr($name, $pos + 1) : $name;
    }

    /** The first line of the recorded exception — the class + message, minus the stack trace. */
    private function exceptionHead(?string $exception): string
    {
        $first = trim((string) strtok((string) $exception, "\n"));

        return $first !== '' ? mb_strimwidth($first, 0, 200, '…') : 'no exception recorded';
    }
}
