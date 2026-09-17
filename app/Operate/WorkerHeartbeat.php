<?php

namespace App\Operate;

use App\Models\QueueWorker;
use Illuminate\Queue\Events\JobFailed;
use Illuminate\Queue\Events\JobProcessed;
use Illuminate\Queue\Events\JobProcessing;
use Illuminate\Queue\Events\JobTimedOut;
use Illuminate\Queue\Events\Looping;
use Illuminate\Queue\Events\WorkerStopping;
use Illuminate\Queue\WorkerStopReason;
use Throwable;

/**
 * A `queue:work` process reporting on itself. Registered once per process against the queue events: the
 * loop tick (a heartbeat, throttled to one write per {@see HEARTBEAT_SECONDS}), a job starting (what it
 * is working on, since when), a job finishing (the tally), and the worker stopping (WHY — memory, max
 * time, a restart signal, a job timeout). Written to `queue_workers` so the operator's Queue page and
 * the stalled-worker banner read facts instead of inferring "down" from an untouched backlog.
 *
 * Only a daemon worker reports: the row is created on the first loop tick, and job events before that
 * (the `sync` driver inside a web request, a one-off `queue:work --once`) touch nothing. A write must
 * never take the worker down with it — every write swallows its own failure.
 */
final class WorkerHeartbeat
{
    public const HEARTBEAT_SECONDS = 15;

    /** Rows older than this are pruned on the next worker start — the page shows recent history only. */
    public const RETENTION_DAYS = 7;

    private ?QueueWorker $worker = null;

    private int $lastBeat = 0;

    public static function workerId(): string
    {
        return gethostname().'#'.getmypid();
    }

    public function looping(Looping $event): void
    {
        $this->guard(function () use ($event): void {
            if ($this->worker === null) {
                $this->start($event->connectionName, (string) $event->queue);

                return;
            }
            if (time() - $this->lastBeat < self::HEARTBEAT_SECONDS) {
                return;
            }
            $this->touch();
        });
    }

    public function processing(JobProcessing $event): void
    {
        $this->guard(function () use ($event): void {
            if ($this->worker === null) {
                return;
            }
            $this->worker->forceFill([
                'current_job' => $event->job->resolveName(),
                'current_queue' => $event->job->getQueue(),
                'current_job_started_at' => now(),
                'last_seen_at' => now(),
                'memory_mb' => self::memoryMb(),
            ])->save();
            $this->lastBeat = time();
        });
    }

    public function processed(JobProcessed $event): void
    {
        $this->guard(function (): void {
            if ($this->worker === null) {
                return;
            }
            $this->worker->forceFill([
                'current_job' => null,
                'current_queue' => null,
                'current_job_started_at' => null,
                'jobs_processed' => $this->worker->jobs_processed + 1,
                'last_seen_at' => now(),
                'memory_mb' => self::memoryMb(),
            ])->save();
            $this->lastBeat = time();
        });
    }

    public function failed(JobFailed $event): void
    {
        $this->guard(function (): void {
            if ($this->worker === null) {
                return;
            }
            $this->worker->forceFill([
                'current_job' => null,
                'current_queue' => null,
                'current_job_started_at' => null,
                'jobs_failed' => $this->worker->jobs_failed + 1,
                'last_seen_at' => now(),
                'memory_mb' => self::memoryMb(),
            ])->save();
            $this->lastBeat = time();
        });
    }

    /**
     * A job that overran its timeout: Laravel KILLS the worker process for it, and that death is not a
     * clean stop — {@see stopping()} never runs, so the row would sit there alive-looking, holding a job,
     * with no reason recorded. That is exactly what a silent worker row has looked like all day. Stamp the
     * cause here, while the process still exists.
     */
    public function timedOut(JobTimedOut $event): void
    {
        $this->guard(function () use ($event): void {
            if ($this->worker === null) {
                return;
            }
            $name = $event->job->resolveName();
            $this->worker->forceFill([
                'stopped_at' => now(),
                'stop_reason' => "killed: {$name} ran past its timeout",
                'last_seen_at' => now(),
            ])->save();
        });
    }

    public function stopping(WorkerStopping $event): void
    {
        $this->guard(function () use ($event): void {
            if ($this->worker === null) {
                return;
            }
            $this->worker->forceFill([
                'stopped_at' => now(),
                'stop_reason' => self::stopReason($event),
                'last_seen_at' => now(),
                'memory_mb' => $event->memoryUsage !== null ? (int) round((float) $event->memoryUsage) : self::memoryMb(),
            ])->save();
        });
    }

    /**
     * The stop reason in operator words. The framework names the cause; the exit status alone is the
     * fallback (12 = memory, 1 = error/timeout, 0 = a clean exit).
     */
    public static function stopReason(WorkerStopping $event): string
    {
        $reason = $event->reason instanceof WorkerStopReason ? $event->reason : null;
        $limit = $event->workerOptions->memory ?? null;

        return match (true) {
            $reason === WorkerStopReason::MaxMemoryExceeded => 'memory limit exceeded'.($limit !== null ? " ({$limit} MB)" : ''),
            $reason === WorkerStopReason::TimedOut => 'a job ran past its timeout',
            $reason === WorkerStopReason::MaxTimeExceeded => 'max-time reached',
            $reason === WorkerStopReason::MaxJobsExceeded => 'max-jobs reached',
            $reason === WorkerStopReason::QueueEmpty, $reason === WorkerStopReason::QueueEmptyFor => 'queue empty (stop-when-empty)',
            $reason === WorkerStopReason::ReceivedRestartSignal => 'queue:restart signal',
            $reason === WorkerStopReason::Interrupted => 'interrupted (SIGTERM / deploy)',
            $reason === WorkerStopReason::LostConnection => 'lost the database connection',
            (int) $event->status === 12 => 'memory limit exceeded',
            (int) $event->status === 1 => 'error exit (a job timeout or a loop error)',
            default => 'exited cleanly',
        };
    }

    private function start(?string $connection, string $queues): void
    {
        QueueWorker::query()->where('last_seen_at', '<', now()->subDays(self::RETENTION_DAYS))->delete();

        $this->worker = QueueWorker::query()->updateOrCreate(
            ['worker_id' => self::workerId()],
            [
                'hostname' => (string) gethostname(),
                'pid' => (int) getmypid(),
                'connection' => $connection,
                'queues' => $queues,
                'started_at' => now(),
                'last_seen_at' => now(),
                'current_job' => null,
                'current_queue' => null,
                'current_job_started_at' => null,
                'jobs_processed' => 0,
                'jobs_failed' => 0,
                'memory_mb' => self::memoryMb(),
                'stopped_at' => null,
                'stop_reason' => null,
            ],
        );
        $this->lastBeat = time();
    }

    private function touch(): void
    {
        $this->worker?->forceFill(['last_seen_at' => now(), 'memory_mb' => self::memoryMb()])->save();
        $this->lastBeat = time();
    }

    private static function memoryMb(): int
    {
        return (int) round(memory_get_usage(true) / 1048576);
    }

    private function guard(callable $write): void
    {
        try {
            $write();
        } catch (Throwable) {
            // A heartbeat must never take the worker down with it.
        }
    }
}
