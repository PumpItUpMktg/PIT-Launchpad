<?php

namespace App\Models;

use App\Models\Concerns\BelongsToSite;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * One provider×range sync attempt (§ Client Dashboard v1). Gives operators visibility into freshness,
 * makes backfill resumable (skip ranges already covered by a success), and backs the "data through {date}"
 * the client UI shows.
 *
 * @property string $site_id
 * @property string $provider
 * @property Carbon|null $range_start
 * @property Carbon|null $range_end
 * @property string $status
 * @property int $rows_written
 * @property string|null $error_message
 * @property Carbon|null $started_at
 * @property Carbon|null $finished_at
 */
class MetricSyncRun extends Model
{
    use BelongsToSite, HasUlids;

    public const STATUS_RUNNING = 'running';

    public const STATUS_SUCCESS = 'success';

    public const STATUS_PARTIAL = 'partial';

    public const STATUS_FAILED = 'failed';

    protected $guarded = [];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'range_start' => 'date',
            'range_end' => 'date',
            'rows_written' => 'integer',
            'started_at' => 'datetime',
            'finished_at' => 'datetime',
        ];
    }
}
