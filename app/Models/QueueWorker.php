<?php

namespace App\Models;

use App\Operate\WorkerHeartbeat;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
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

    /** The lanes this worker polls, in the order it polls them. @return list<string> */
    public function lanes(): array
    {
        return array_values(array_filter(array_map('trim', explode(',', (string) $this->queues)), fn (string $q): bool => $q !== ''));
    }
}
