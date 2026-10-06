<?php

namespace App\Activity;

use App\Models\ActivityEvent;
use Illuminate\Support\Carbon;

/**
 * Records an operator action on the activity log (§ Activity) — only the actions that leave no other
 * durable trace. One row, one plain sentence; the log reads it back beside the derived entries.
 */
final class ActivityRecorder
{
    public const REPUSH = 'repush';

    public const CONTENT_REJECTED = 'content_rejected';

    public const PRIORITY_KEYWORD = 'priority_keyword';

    public const PRIORITY_PUSH = 'priority_push';

    public const PUBLISH_RELEASED = 'publish_released';

    /** @param  array<string, int|float|string>  $metrics */
    public function record(string $siteId, string $kind, string $summary, array $metrics = [], ?string $subject = null, ?string $actorId = null, bool $clientVisible = false): ActivityEvent
    {
        return ActivityEvent::withoutGlobalScopes()->create([
            'site_id' => $siteId,
            'kind' => $kind,
            'occurred_at' => Carbon::now(),
            'subject' => $subject !== null ? mb_substr($subject, 0, 255) : null,
            'summary' => mb_substr(trim($summary), 0, 500),
            'metrics' => $metrics === [] ? null : $metrics,
            'actor_id' => $actorId,
            'client_visible' => $clientVisible,
        ]);
    }
}
