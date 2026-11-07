<?php

namespace App\Jobs;

use App\Models\Site;
use App\TownRank\ServiceAreaView;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

/**
 * Build one service area's page into the cache ({@see ServiceAreaView}) — the heavy read that must never
 * run inside a web request. Unique per site + location; same lane as the Town Rank collectors.
 */
class BuildServiceArea implements ShouldBeUnique, ShouldQueue
{
    use Queueable;

    public int $tries = 1;

    public int $timeout = 600;

    public int $uniqueFor = 600;

    public function __construct(public readonly string $siteId, public readonly string $locationId)
    {
        $queue = config('launchpad.town_rank.queue');
        if (is_string($queue) && $queue !== '') {
            $this->onQueue($queue);
        }
    }

    public function uniqueId(): string
    {
        return $this->siteId.':'.$this->locationId;
    }

    public function handle(): void
    {
        $site = Site::withoutGlobalScopes()->find($this->siteId);
        if ($site === null) {
            return;
        }
        ServiceAreaView::build($site, $this->locationId);
    }
}
