<?php

namespace App\Operate;

use App\Models\Content;
use App\Models\Scopes\SiteScope;
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
     * Job classes whose failed_jobs rows are BENIGN noise — idempotent, self-healing infra (cache warmers)
     * that a deploy/worker-restart can interrupt mid-run. They must never trip the "stalled" banner or ask
     * the operator to "clear + regenerate a page" (they touch no page). Basename match on the payload.
     */
    private const BENIGN = ['WarmLiveMetrics'];

    /**
     * @return array{pending: int, oldest_minutes: int, failed: int, processing: int, draining: bool, worker_down: bool, stalled: bool}
     */
    public function snapshot(int $stalledAfterMinutes = 5): array
    {
        $pending = (int) DB::table('jobs')->count();
        $oldestAvailableAt = DB::table('jobs')->min('available_at');
        $oldestMinutes = $oldestAvailableAt !== null
            ? (int) floor(max(0, time() - (int) $oldestAvailableAt) / 60)
            : 0;
        // Benign warm-cache failures are self-healing noise — exclude them so an interrupted warm never
        // shows a phantom "stalled" fault.
        $failedQuery = DB::table('failed_jobs');
        foreach (self::BENIGN as $class) {
            $failedQuery->where('payload', 'not like', '%'.$class.'%');
        }
        $failed = (int) $failedQuery->count();

        // A job the worker is holding right now: `reserved_at` is stamped when a worker reserves a job
        // and cleared/deleted when it finishes. A recent reservation = a LIVE worker actively draining —
        // the difference between "clearing one page at a time" (fine) and "nobody is working" (down).
        $processing = (int) DB::table('jobs')
            ->whereNotNull('reserved_at')
            ->where('reserved_at', '>=', time() - $stalledAfterMinutes * 60)
            ->count();

        // Worker DOWN = an ageing backlog that nothing is processing. An ageing backlog WITH a job in
        // flight is just a slow drain (publish = render + WP push, one at a time), not a fault.
        $agingBacklog = $pending > 0 && $oldestMinutes >= $stalledAfterMinutes;
        $workerDown = $agingBacklog && $processing === 0;

        return [
            'pending' => $pending,
            'oldest_minutes' => $oldestMinutes,
            'failed' => $failed,
            'processing' => $processing,
            // Draining = there's a backlog AND a worker is actively chewing through it → inform, don't alarm.
            'draining' => $pending > 0 && $processing > 0,
            'worker_down' => $workerDown,
            // Stalled is a real fault only: the worker is down, or a job has failed. A healthy drain
            // (backlog shrinking, a job in flight) is NOT stalled — it just needs to be shown as progress.
            'stalled' => $workerDown || $failed > 0,
        ];
    }

    /**
     * The failed jobs grouped by their cause — job class ✕ first exception line, most-frequent first —
     * so the banner can show WHAT failed, WHY, and WHICH page(s). Mirrors launchpad:queue-diagnose.
     *
     * @return list<array{job: string, reason: string, count: int, last: string, pages: list<string>}>
     */
    public function failures(int $limit = 8): array
    {
        $rows = DB::table('failed_jobs')->orderByDesc('failed_at')->get()
            ->reject(fn (object $r): bool => in_array($this->jobClass($r->payload), self::BENIGN, true)) // hide benign warm noise
            ->values();

        // Resolve every referenced content id to a page title in ONE query — most failed jobs
        // (GeneratePage / GeneratePost / PublishContent / RenderImage) carry the content id they act on.
        $titles = Content::withoutGlobalScope(SiteScope::class)
            ->whereIn('id', $rows->map(fn (object $r): ?string => $this->contentIdFromPayload($r->payload))->filter()->unique()->values())
            ->pluck('title', 'id');

        return $rows
            ->groupBy(fn (object $row): string => $this->jobClass($row->payload).'  ✕  '.$this->exceptionHead($row->exception))
            ->map(fn ($group): array => [
                'job' => $this->jobClass($group->first()->payload),
                'reason' => $this->friendlyReason($this->exceptionHead($group->first()->exception)),
                'count' => $group->count(),
                'last' => (string) $group->max('failed_at'),
                'pages' => $group
                    ->map(fn (object $r): ?string => $this->contentIdFromPayload($r->payload))
                    ->filter()
                    ->map(fn (string $id): string => trim((string) ($titles[$id] ?? '')))
                    ->filter(fn (string $t): bool => $t !== '')
                    ->unique()
                    ->values()
                    ->all(),
            ])
            ->sortByDesc('count')
            ->take($limit)
            ->values()
            ->all();
    }

    /**
     * The content id a job acts on, pulled from its serialized command in the failed_jobs payload
     * (jobs carry `contentId`). Null for jobs that reference no content. Read-only string extraction —
     * never unserialize an arbitrary job object.
     */
    private function contentIdFromPayload(?string $payload): ?string
    {
        $data = json_decode((string) $payload, true);
        $command = is_array($data) ? ($data['data']['command'] ?? null) : null;
        if (! is_string($command)) {
            return null;
        }

        return preg_match('/"contentId";s:\d+:"([^"]+)"/', $command, $m) === 1 ? $m[1] : null;
    }

    /**
     * Turn a raw framework exception head into an operator-legible cause. The common one — a
     * MaxAttemptsExceeded on a tries=1 generate/publish job — means the run was INTERRUPTED (a deploy
     * restarted the worker mid-job, or it ran past the timeout), not that the page is broken; the fix
     * is to clear + regenerate. Anything else passes through as the real error (WP 401, fal 402, …).
     */
    private function friendlyReason(string $exceptionHead): string
    {
        if (str_contains($exceptionHead, 'MaxAttemptsExceeded') || str_contains($exceptionHead, 'attempted too many times')) {
            return 'Generation was interrupted (a deploy restarted the worker mid-run, or it ran past the timeout) — clear this, then regenerate the page.';
        }

        return $exceptionHead;
    }

    /** Delete every failed_jobs row (the operator "Clear failed" action / queue:flush). Returns the count. */
    public function clearFailed(): int
    {
        return DB::table('failed_jobs')->delete();
    }

    /**
     * Delete only the BENIGN (self-healing infra) failed_jobs rows — the warm-cache jobs a deploy/timeout
     * can interrupt. Called on the scheduled warm so this noise auto-clears and the operator never has to
     * hand-clear it. Returns the count removed.
     */
    public function pruneBenignFailures(): int
    {
        return DB::table('failed_jobs')
            ->where(function ($q): void {
                foreach (self::BENIGN as $class) {
                    $q->orWhere('payload', 'like', '%'.$class.'%');
                }
            })
            ->delete();
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
