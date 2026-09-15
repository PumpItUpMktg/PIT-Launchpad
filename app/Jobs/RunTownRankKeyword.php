<?php

namespace App\Jobs;

use App\Models\Keyword;
use App\Models\Scopes\SiteScope;
use App\Models\Site;
use App\Models\TownRankScan;
use App\TownRank\TownRankScanner;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * POSTS one keyword's town-rank scans (both query modes, skipping a mode whose latest scan is still
 * collecting) on the queue — the "Run ranking report" button on the Town Rank wall. Posting is a handful of
 * rate-limited task_post calls per mode; the {@see IngestTownRankScans} sweep collects the results. Each mode
 * posts independently: a vendor error on one is logged and the other still goes out (a half-posted keyword
 * shows "not scanned" for the missing mode, and Run posts just that mode next time). `tries = 1`.
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
            Log::warning('Town-rank run: site or keyword not found; nothing posted.', ['site_id' => $this->siteId, 'keyword_id' => $this->keywordId]);

            return;
        }
        Log::info('Town-rank run: started.', ['site_id' => $site->id, 'keyword_id' => $keyword->id, 'query' => (string) $keyword->query]);

        foreach (TownRankScan::MODES as $mode) {
            $latest = TownRankScan::withoutGlobalScope(SiteScope::class)
                ->where('site_id', $site->id)->where('keyword_id', $keyword->id)->where('mode', $mode)
                ->orderByDesc('scanned_at')->first();
            if ($latest !== null && $latest->status === 'pending') {
                Log::info('Town-rank run: mode already collecting; skipped.', ['keyword_id' => $keyword->id, 'mode' => $mode, 'scan_id' => $latest->id]);

                continue;
            }
            try {
                $scan = $scanner->post($site, $keyword, $mode);
                if ($scan === null) {
                    Log::warning('Town-rank run: no scannable towns; nothing posted for this mode.', ['site_id' => $site->id, 'keyword_id' => $keyword->id, 'mode' => $mode]);
                } else {
                    Log::info('Town-rank run: posted.', ['keyword_id' => $keyword->id, 'mode' => $mode, 'scan_id' => $scan->id, 'towns' => $scan->points_count]);
                }
            } catch (Throwable $e) {
                Log::warning('Town-rank run: posting a mode failed; the other mode still posts.', [
                    'site_id' => $site->id, 'keyword_id' => $keyword->id, 'mode' => $mode, 'error' => mb_substr($e->getMessage(), 0, 300),
                ]);
            }
        }
    }
}
