<?php

namespace App\TownRank;

use App\Models\Keyword;
use App\Models\Scopes\SiteScope;
use App\Models\Site;

/**
 * "Run the website rankings for every tracked keyword" — the sitewide button's plan and its run.
 *
 * It delegates to {@see TownRankKeywords}, which QUEUES one job per keyword, rather than to
 * {@see TownRankSweep}, which posts every scan inline. The sweep is a console command and posting
 * hundreds of DataForSEO batches in a row is fine on a console clock; doing it inside a Livewire request
 * is how you get an FPM timeout halfway through, having already paid for the half that posted.
 *
 * One job per keyword also means the work arrives at the worker in small independent pieces: a keyword
 * that fails takes only itself down, and the queue drains steadily instead of in one shove.
 */
final class TownRankRunAll
{
    public function __construct(private readonly TownRankKeywords $keywords) {}

    /**
     * @return array{towns: int, runnable: list<Keyword>, tracked: int, pending: int, blocked: int, requests: int, cost: float}
     */
    public function plan(Site $site): array
    {
        $tracked = Keyword::withoutGlobalScope(SiteScope::class)
            ->where('site_id', $site->id)
            ->where(fn ($q) => $q->where('is_grid_keyword', true)->orWhere('track_town_rank', true))
            ->orderBy('query')
            ->get();

        $runnable = [];
        $requests = 0;
        $cost = 0.0;
        $pending = 0;
        $blocked = 0;
        $towns = 0;

        foreach ($tracked as $keyword) {
            $estimate = $this->keywords->estimate($site, $keyword);
            $towns = $estimate['towns'];
            if ($estimate['modes'] === []) {
                $pending++;   // already collecting; its requests are bought

                continue;
            }
            if ($estimate['over_ceiling']) {
                $blocked++;   // refused individually, and said so rather than trimmed

                continue;
            }
            $runnable[] = $keyword;
            $requests += $estimate['requests'];
            $cost += $estimate['cost'];
        }

        return [
            'towns' => $towns,
            'runnable' => $runnable,
            'tracked' => $tracked->count(),
            'pending' => $pending,
            'blocked' => $blocked,
            'requests' => $requests,
            'cost' => round($cost, 2),
        ];
    }

    /**
     * Queue one job per runnable keyword. Each goes through the same guard the per-card button uses, so a
     * keyword that became pending between planning and running is still refused rather than bought twice.
     *
     * @return array{queued: int, skipped: int, requests: int, cost: float}
     */
    public function run(Site $site): array
    {
        $plan = $this->plan($site);
        $queued = 0;
        $skipped = 0;

        foreach ($plan['runnable'] as $keyword) {
            if ($this->keywords->run($site, $keyword)['queued']) {
                $queued++;

                continue;
            }
            $skipped++;
        }

        return ['queued' => $queued, 'skipped' => $skipped, 'requests' => $plan['requests'], 'cost' => $plan['cost']];
    }
}
