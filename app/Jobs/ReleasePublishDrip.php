<?php

namespace App\Jobs;

use App\Models\Site;
use App\Publishing\Drip\PublishDrip;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Hourly: for every site with the publish drip on, release as many queued pages as there are free slots
 * (batch − pages still waiting for Google). Idempotent — a run with no slots releases nothing.
 */
class ReleasePublishDrip implements ShouldQueue
{
    use Queueable;

    public int $timeout = 600;

    public function handle(PublishDrip $drip): void
    {
        foreach (Site::withoutGlobalScopes()->get() as $site) {
            if (! $site->publishDrip()['enabled']) {
                continue;
            }
            try {
                $released = $drip->release($site);
                if ($released !== []) {
                    Log::info('Publish drip released', ['site_id' => $site->id, 'pages' => count($released)]);
                }
            } catch (Throwable $e) {
                Log::warning('Publish drip failed', ['site_id' => $site->id, 'error' => $e->getMessage()]);
            }
        }
    }
}
