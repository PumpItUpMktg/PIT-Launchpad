<?php

namespace App\Jobs;

use App\Models\Keyword;
use App\Models\Scopes\SiteScope;
use App\Models\Site;
use App\Models\TownRankScan;
use App\TownRank\TownRankScanner;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

/**
 * POSTS one keyword's town-rank scans (both query modes, skipping a mode whose latest scan is still
 * collecting) on the queue — the "Run ranking report" button on the Town Rank wall. Posting is a couple of
 * rate-limited task_post calls; the {@see IngestTownRankScans} sweep collects the results. `tries = 1`: the
 * operator can press the button again.
 */
class RunTownRankKeyword implements ShouldQueue
{
    use Queueable;

    public int $timeout = 600;

    public int $tries = 1;

    public function __construct(public readonly string $siteId, public readonly string $keywordId)
    {
        $queue = config('launchpad.town_rank.queue');
        if (is_string($queue) && $queue !== '') {
            $this->onQueue($queue);
        }
    }

    public function handle(TownRankScanner $scanner): void
    {
        $site = Site::withoutGlobalScopes()->find($this->siteId);
        $keyword = Keyword::withoutGlobalScope(SiteScope::class)->where('site_id', $this->siteId)->whereKey($this->keywordId)->first();
        if ($site === null || $keyword === null) {
            return;
        }

        foreach (TownRankScan::MODES as $mode) {
            $latest = TownRankScan::withoutGlobalScope(SiteScope::class)
                ->where('site_id', $site->id)->where('keyword_id', $keyword->id)->where('mode', $mode)
                ->orderByDesc('scanned_at')->first();
            if ($latest !== null && $latest->status === 'pending') {
                continue;
            }
            $scanner->post($site, $keyword, $mode);
        }
    }
}
