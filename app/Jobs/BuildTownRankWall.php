<?php

namespace App\Jobs;

use App\Models\Site;
use App\TownRank\TownRankWall;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

/**
 * Build one site's Town Rank wall into the cache ({@see TownRankWall}) — the heavy read that must never
 * run inside a web request. Unique per site: a wall already queued or building is not queued again, so a
 * page polling every 30 s and a collector finishing a scan in the same minute cost one build. Same lane as
 * the collectors, so the wall and the collection it describes share the worker's time honestly.
 */
class BuildTownRankWall implements ShouldBeUnique, ShouldQueue
{
    use Queueable;

    public int $tries = 1;

    public int $timeout = 600;

    public int $uniqueFor = 600;

    public function __construct(public readonly string $siteId)
    {
        $queue = config('launchpad.town_rank.queue');
        if (is_string($queue) && $queue !== '') {
            $this->onQueue($queue);
        }
    }

    public function uniqueId(): string
    {
        return $this->siteId;
    }

    public function handle(): void
    {
        $site = Site::withoutGlobalScopes()->find($this->siteId);
        if ($site === null) {
            return;
        }
        TownRankWall::build($site);
    }
}
