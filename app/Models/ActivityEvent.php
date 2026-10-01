<?php

namespace App\Models;

use App\Activity\ActivityRecorder;
use App\Models\Concerns\BelongsToSite;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * An operator action the activity log shows that leaves no other durable trace (§ Activity): a manual
 * re-push, a rejection, a priority push from the Service Areas map. Written by {@see ActivityRecorder};
 * everything else on the log is derived from the records the work itself writes.
 *
 * @property string $id
 * @property string $site_id
 * @property string $kind
 * @property Carbon $occurred_at
 * @property string|null $subject
 * @property string $summary
 * @property array<string, mixed>|null $metrics
 * @property string|null $actor_id
 * @property bool $client_visible
 */
class ActivityEvent extends Model
{
    use BelongsToSite, HasUlids;

    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'occurred_at' => 'datetime',
            'metrics' => 'array',
            'client_visible' => 'boolean',
        ];
    }
}
