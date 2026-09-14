<?php

namespace App\Jobs;

use App\Models\Site;
use App\TownRank\TownRankSweep;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Log;

/**
 * POSTS one site's due town-rank scans (§ Town Rank, PR 3) on the queue — a handful of rate-limited task_post
 * calls — and returns; the {@see IngestTownRankScans} sweep collects them. One job per site so a site over
 * its ceiling (logged, skipped) or a vendor error isolates from the rest. `tries = 1`: the weekly cadence is
 * the retry.
 */
class RunTownRankSweep implements ShouldQueue
{
    use Queueable;

    public int $timeout = 600;

    public int $tries = 1;

    public function __construct(public readonly string $siteId)
    {
        $queue = config('launchpad.town_rank.queue');
        if (is_string($queue) && $queue !== '') {
            $this->onQueue($queue);
        }
    }

    public function handle(TownRankSweep $sweep): void
    {
        $site = Site::withoutGlobalScopes()->find($this->siteId);
        if ($site === null) {
            return;
        }

        $result = $sweep->run($site);
        if ($result['over_ceiling']) {
            Log::warning('Town-rank sweep skipped: due set exceeds the request ceiling.', ['site_id' => $site->id, 'requests' => $result['requests']]);
        }
    }
}
