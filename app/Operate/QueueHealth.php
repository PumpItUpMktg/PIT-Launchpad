<?php

namespace App\Operate;

use App\Jobs\SyncSiteMetrics;
use App\Metrics\Providers\DataForSeoMetricProvider;
use App\Metrics\Providers\Ga4MetricProvider;
use App\Metrics\Providers\GscMetricProvider;
use App\Metrics\Providers\IndexMetricProvider;
use App\Models\Content;
use App\Models\QueueWorker;
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
     * A worker is LIVE while its heartbeat is this fresh (the loop ticks every few seconds; the beat is
     * written every {@see WorkerHeartbeat::HEARTBEAT_SECONDS}), or while it is inside a job — a long job
     * writes no ticks, so a worker holding one counts as live until the job could not still be running.
     */
    public const LIVE_WITHIN_SECONDS = 90;

    public const BUSY_GRACE_MINUTES = 20;

    /**
     * @return array{pending: int, oldest_minutes: int, failed: int, processing: int, draining: bool, worker_down: bool, stalled: bool, lanes: list<array{queue: string, pending: int, reserved: int, oldest_minutes: int, expected: bool, workers: list<string>, alive: bool, busy: ?string, down: bool}>, silent_lanes: list<string>}
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

        // Worker DOWN, by lane, from the workers' own heartbeats: a lane with an ageing backlog and no live
        // worker listening. Before any worker has reported (a deploy older than the heartbeat), fall back to
        // the backlog-only tell — an ageing backlog that nothing is processing. An ageing backlog WITH a job
        // in flight is just a slow drain (publish = render + WP push, one at a time), not a fault.
        $lanes = $this->lanes($stalledAfterMinutes);
        $reporting = QueueWorker::query()->exists();
        $agingBacklog = $pending > 0 && $oldestMinutes >= $stalledAfterMinutes;
        $workerDown = $reporting
            ? array_filter($lanes, fn (array $l): bool => $l['down']) !== []
            : $agingBacklog && $processing === 0;
        $busy = array_filter($lanes, fn (array $l): bool => $l['busy'] !== null) !== [];

        return [
            'pending' => $pending,
            'oldest_minutes' => $oldestMinutes,
            'failed' => $failed,
            'processing' => $processing,
            // Draining = there's a backlog AND a worker is actively chewing through it → inform, don't alarm.
            'draining' => $pending > 0 && ($processing > 0 || $busy),
            'worker_down' => $workerDown,
            // Stalled is a real fault only: the worker is down, or a job has failed. A healthy drain
            // (backlog shrinking, a job in flight) is NOT stalled — it just needs to be shown as progress.
            'stalled' => $workerDown || $failed > 0,
            'lanes' => $lanes,
            // The lanes holding jobs that no live worker polls — the banner names them.
            'silent_lanes' => array_values(array_map(fn (array $l): string => $l['queue'], array_filter($lanes, fn (array $l): bool => $l['pending'] > 0 && ! $l['alive']))),
        ];
    }

    /**
     * One row per lane (queue name): every lane the app is configured to dispatch on, plus any lane that
     * currently holds jobs — with its backlog and the live workers polling it. A lane with jobs and no
     * live listener is the exact "why isn't this running" answer (a worker died, or nobody was ever
     * started for that queue name).
     *
     * @return list<array{queue: string, pending: int, reserved: int, oldest_minutes: int, expected: bool, workers: list<string>, alive: bool, busy: ?string, down: bool}>
     */
    public function lanes(int $stalledAfterMinutes = 5): array
    {
        $now = time();
        $backlog = DB::table('jobs')
            ->selectRaw('queue, count(*) as pending, sum(case when reserved_at is not null then 1 else 0 end) as reserved, min(available_at) as oldest')
            ->groupBy('queue')->get()->keyBy('queue');
        $expected = $this->expectedLanes();
        $names = collect($expected)->merge($backlog->keys())->unique()->values();
        $workers = $this->workers();

        $lanes = [];
        foreach ($names as $queue) {
            $row = $backlog->get($queue);
            $listening = array_values(array_filter($workers, fn (array $w): bool => in_array($queue, $w['lanes'], true) && $w['alive']));
            $busy = collect($listening)->first(fn (array $w): bool => $w['current_job'] !== null);
            $pending = $row !== null ? (int) $row->pending : 0;
            $oldest = $row !== null && $row->oldest !== null ? (int) floor(max(0, $now - (int) $row->oldest) / 60) : 0;
            $lanes[] = [
                'queue' => (string) $queue,
                'pending' => $pending,
                'reserved' => $row !== null ? (int) $row->reserved : 0,
                'oldest_minutes' => $oldest,
                'expected' => in_array($queue, $expected, true),
                'workers' => array_map(fn (array $w): string => $w['worker_id'], $listening),
                'alive' => $listening !== [],
                'busy' => $busy !== null ? (string) $busy['current_job'] : null,
                'down' => $pending > 0 && $oldest >= $stalledAfterMinutes && $listening === [],
            ];
        }

        return $lanes;
    }

    /**
     * Every worker that has reported in the retention window, freshest first, with its liveness resolved.
     *
     * @return list<array{worker_id: string, hostname: string, pid: int, queues: string, lanes: list<string>, started_at: string, last_seen_at: string, seconds_since_seen: int, current_job: ?string, current_queue: ?string, job_seconds: ?int, jobs_processed: int, jobs_failed: int, memory_mb: int, stopped_at: ?string, stop_reason: ?string, alive: bool, state: string}>
     */
    public function workers(): array
    {
        $now = now();

        return QueueWorker::query()->orderByDesc('last_seen_at')->get()
            ->map(function (QueueWorker $w) use ($now): array {
                $since = (int) $w->last_seen_at->diffInSeconds($now, true);
                $jobSeconds = $w->current_job_started_at !== null ? (int) $w->current_job_started_at->diffInSeconds($now, true) : null;
                $alive = $w->stopped_at === null && (
                    $since <= self::LIVE_WITHIN_SECONDS
                    || ($w->current_job !== null && $jobSeconds !== null && $jobSeconds <= self::BUSY_GRACE_MINUTES * 60)
                );
                $state = match (true) {
                    $w->stopped_at !== null => 'stopped',
                    $alive && $w->current_job !== null => 'working',
                    $alive => 'idle',
                    default => 'silent',
                };

                return [
                    'worker_id' => $w->worker_id,
                    'hostname' => $w->hostname,
                    'pid' => $w->pid,
                    'queues' => (string) $w->queues,
                    'lanes' => $w->lanes(),
                    'started_at' => $w->started_at->toDateTimeString(),
                    'last_seen_at' => $w->last_seen_at->toDateTimeString(),
                    'seconds_since_seen' => $since,
                    'current_job' => $w->current_job !== null ? self::basename($w->current_job) : null,
                    'current_queue' => $w->current_queue,
                    'job_seconds' => $jobSeconds,
                    'jobs_processed' => $w->jobs_processed,
                    'jobs_failed' => $w->jobs_failed,
                    'memory_mb' => $w->memory_mb,
                    'stopped_at' => $w->stopped_at?->toDateTimeString(),
                    'stop_reason' => $w->stop_reason,
                    'alive' => $alive,
                    // silent = never stopped cleanly, no heartbeat: killed, or its host never restarted it.
                    'state' => $state,
                ];
            })
            ->values()->all();
    }

    /**
     * The lanes this app dispatches on: `default`, the configured Town Rank / geo-grid lanes, and the
     * metric-sync lanes (per provider unless LAUNCHPAD_METRICS_QUEUE folds them into one).
     *
     * @return list<string>
     */
    public function expectedLanes(): array
    {
        $lanes = ['default'];
        foreach (['launchpad.town_rank.queue', 'launchpad.geo_grid.queue'] as $key) {
            $q = config($key);
            if (is_string($q) && trim($q) !== '') {
                $lanes[] = trim($q);
            }
        }
        foreach ([GscMetricProvider::PROVIDER, DataForSeoMetricProvider::PROVIDER, Ga4MetricProvider::PROVIDER, IndexMetricProvider::PROVIDER] as $provider) {
            $lanes[] = SyncSiteMetrics::queueFor($provider);
        }

        return array_values(array_unique($lanes));
    }

    private static function basename(string $class): string
    {
        return ($pos = strrpos($class, '\\')) !== false ? substr($class, $pos + 1) : $class;
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
