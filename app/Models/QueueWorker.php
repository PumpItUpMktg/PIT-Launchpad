<?php

namespace App\Models;

use App\Operate\WorkerHeartbeat;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Queue\Worker;
use Illuminate\Support\Carbon;

/**
 * One `queue:work` process, as it reports itself (see {@see WorkerHeartbeat}). GLOBAL — not
 * site-scoped. A row with `stopped_at` null and a stale `last_seen_at` is a worker that vanished without
 * stopping cleanly (killed, or its host never restarted it).
 *
 * @property string $id
 * @property string $worker_id
 * @property string $hostname
 * @property int $pid
 * @property string|null $connection
 * @property string|null $queues
 * @property Carbon $started_at
 * @property Carbon $last_seen_at
 * @property string|null $current_job
 * @property string|null $current_queue
 * @property Carbon|null $current_job_started_at
 * @property int $jobs_processed
 * @property int $jobs_failed
 * @property int $memory_mb
 * @property Carbon|null $stopped_at
 * @property string|null $stop_reason
 */
class QueueWorker extends Model
{
    use HasUlids;

    protected $guarded = [];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'pid' => 'integer',
            'started_at' => 'datetime',
            'last_seen_at' => 'datetime',
            'current_job_started_at' => 'datetime',
            'jobs_processed' => 'integer',
            'jobs_failed' => 'integer',
            'memory_mb' => 'integer',
            'stopped_at' => 'datetime',
        ];
    }

    /**
     * The lanes this worker polls, in the order it polls them — VERBATIM, never trimmed.
     *
     * `queue:work` splits its --queue argument on commas and does not trim the parts
     * ({@see Worker::getNextJob()}: `explode(',', $queue)`), so `--queue=high, default`
     * polls `high` and ` default` — and a lane named with a leading space is one nothing ever enqueues to.
     * Trimming here would quietly "correct" the name and hide exactly the fault worth seeing.
     *
     * @return list<string>
     */
    public function lanes(): array
    {
        return array_values(array_filter(explode(',', (string) $this->queues), fn (string $q): bool => $q !== ''));
    }

    /**
     * The lanes whose name carries surrounding whitespace — polled as written, matching nothing.
     *
     * @return list<string>
     */
    public function whitespaceLanes(): array
    {
        return array_values(array_filter($this->lanes(), fn (string $q): bool => $q !== trim($q)));
    }
}
