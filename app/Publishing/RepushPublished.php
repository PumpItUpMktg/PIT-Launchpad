<?php

namespace App\Publishing;

use App\Enums\ContentKind;
use App\Enums\ContentStatus;
use App\Jobs\PublishContent;
use App\Models\Content;
use App\Models\Scopes\SiteScope;
use App\Models\Site;
use Illuminate\Support\Carbon;

/**
 * Bulk re-publish a site's already-live content — re-renders the engine-owned meta-blob
 * (canonical / og:url / schema) without touching the copy, so a stale value baked in at an earlier
 * publish (e.g. a canonical pointing at the old staging host after a domain move) is corrected.
 * Idempotent by ULID (same WP post, same URL) and images are already rendered, so there is no fal
 * spend — it's just a WordPress meta push per item.
 *
 * The re-push is THROTTLED: {@see PublishContent} jobs are dispatched in waves (chunk per interval,
 * staggered by delay) so the client's WordPress never sees a burst of hundreds of simultaneous pushes
 * and the queue drains steadily. The worker must be running for a queued re-push to complete (the
 * queue-health banner + drain button are the backstop).
 */
class RepushPublished
{
    /**
     * @param  list<ContentKind>  $kinds
     * @return array{count: int, waves: int, minutes: float}
     */
    public function dispatch(Site $site, array $kinds, int $chunk = 10, int $intervalSeconds = 15, bool $dryRun = false): array
    {
        $chunk = max(1, $chunk);
        $intervalSeconds = max(0, $intervalSeconds);

        $ids = Content::withoutGlobalScope(SiteScope::class)
            ->where('site_id', $site->id)
            ->whereIn('kind', array_map(fn (ContentKind $k): string => $k->value, $kinds))
            ->where('status', ContentStatus::Published->value)
            ->whereNotNull('wp_post_id')
            ->orderBy('published_at')
            ->pluck('id')
            ->all();

        if (! $dryRun) {
            foreach ($ids as $index => $id) {
                $wave = intdiv($index, $chunk);
                PublishContent::dispatch((string) $id)->delay(Carbon::now()->addSeconds($wave * $intervalSeconds));
            }
        }

        $count = count($ids);
        $waves = (int) ceil($count / $chunk);

        return [
            'count' => $count,
            'waves' => $waves,
            // Time until the LAST wave becomes available (waves - 1 gaps); the worker drains after.
            'minutes' => round(max(0, $waves - 1) * $intervalSeconds / 60, 1),
        ];
    }
}
