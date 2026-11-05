<?php

namespace App\ContentEngine\Questions;

use App\Models\GscUrlQueryDaily;
use App\Models\Scopes\SiteScope;
use App\Models\Site;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Mines the questions a site's searchers typed from the Search Console query store the daily sync already
 * keeps ({@see GscUrlQueryDaily}) — no vendor call, no credit. One pass: aggregate per query over the
 * trailing window (SQL; GSC's own impressions, clicks and impression-weighted position, never re-weighted),
 * keep the question-shaped ({@see QuestionShape}), drop machine traffic ({@see BotQueryGuard}), attach the
 * URLs that carried each survivor, and cluster near-identical phrasings ({@see QuestionCluster}).
 *
 * The result is a report, not a side effect: filing candidates and marking posts is the intake's job.
 */
class QuestionMiner
{
    private const IN_CHUNK = 400;

    public function __construct(private readonly ?BotQueryGuard $guard = null) {}

    public function mine(Site $site, ?int $windowDays = null, ?CarbonImmutable $asOf = null): QuestionMiningReport
    {
        $windowDays = $windowDays ?? (int) config('launchpad.questions.window_days', 90);
        $asOf = $asOf ?? CarbonImmutable::now();
        $from = $asOf->subDays(max(1, $windowDays))->toDateString();
        $to = $asOf->toDateString();
        $guard = $this->guard ?? BotQueryGuard::fromConfig();
        $minWords = (int) config('launchpad.questions.min_words', 3);
        $jaccard = (float) config('launchpad.questions.cluster_jaccard', 0.75);

        $queriesRead = (int) $this->base($site, $from, $to)->distinct()->count('query');

        $brands = array_values(array_filter([(string) $site->brand_name, (string) ($site->domain_url ?? '')], fn (string $b): bool => trim($b) !== ''));

        $stats = $this->aggregate($site, $from, $to)
            ->filter(fn (QuestionStat $s): bool => QuestionShape::isQuestion($s->query, $brands, $minWords))
            ->values();
        $questionShaped = $stats->count();

        $botDropped = [];
        $kept = [];
        foreach ($stats as $stat) {
            $reason = $guard->reason($stat, $asOf);
            if ($reason !== null) {
                $botDropped[] = ['query' => $stat->query, 'reason' => $reason, 'impressions' => $stat->impressions, 'clicks' => $stat->clicks, 'position' => $stat->position];

                continue;
            }
            $kept[] = $stat;
        }

        $kept = $this->attachUrls($site, $from, $to, $kept);
        $clusters = QuestionCluster::cluster($kept, $jaccard);

        return new QuestionMiningReport((string) $site->id, $windowDays, $from, $to, $queriesRead, $questionShaped, $botDropped, $clusters);
    }

    /** @return Builder<GscUrlQueryDaily> */
    private function base(Site $site, string $from, string $to)
    {
        return GscUrlQueryDaily::withoutGlobalScope(SiteScope::class)
            ->where('site_id', $site->id)
            ->whereBetween('date', [$from, $to]);
    }

    /**
     * Per-query aggregates for the question-shaped prefilter (the SQL LIKE mirror of {@see QuestionShape});
     * the PHP pass applies the exact rule afterwards.
     *
     * @return Collection<int, QuestionStat>
     */
    private function aggregate(Site $site, string $from, string $to): Collection
    {
        $like = fn ($q) => $q->where(function ($w): void {
            foreach (QuestionShape::likePatterns() as $pattern) {
                $w->orWhere('query', 'like', $pattern);
            }
        });

        $rows = $like($this->base($site, $from, $to))
            ->selectRaw('query, SUM(impressions) AS imp, SUM(clicks) AS clk, '
                .'SUM(CASE WHEN position IS NULL THEN 0 ELSE position * impressions END) AS wpos, '
                .'SUM(CASE WHEN position IS NULL THEN 0 ELSE impressions END) AS wden, '
                .'MIN(position) AS best, COUNT(DISTINCT date) AS days, MIN(date) AS first_seen, MAX(date) AS last_seen')
            ->groupBy('query')
            ->toBase()
            ->get();

        $peaks = DB::query()->fromSub(
            $like($this->base($site, $from, $to))->selectRaw('query, date, SUM(impressions) AS d')->groupBy('query', 'date')->toBase(),
            'daily',
        )->selectRaw('query, MAX(d) AS peak')->groupBy('query')->pluck('peak', 'query');

        return $rows->map(function (object $r) use ($peaks): QuestionStat {
            $wden = (int) $r->wden;

            return new QuestionStat(
                query: (string) $r->query,
                impressions: (int) $r->imp,
                clicks: (int) $r->clk,
                position: $wden > 0 ? round((float) $r->wpos / $wden, 1) : null,
                bestPosition: $r->best !== null ? (float) $r->best : null,
                daysSeen: (int) $r->days,
                firstSeen: substr((string) $r->first_seen, 0, 10),
                lastSeen: substr((string) $r->last_seen, 0, 10),
                peakDaily: (int) ($peaks[(string) $r->query] ?? 0),
            );
        });
    }

    /**
     * The URL breakdown for the surviving queries only (the whole store's URL×query grain is far larger
     * than the question slice).
     *
     * @param  list<QuestionStat>  $stats
     * @return list<QuestionStat>
     */
    private function attachUrls(Site $site, string $from, string $to, array $stats): array
    {
        if ($stats === []) {
            return [];
        }
        $byQuery = [];
        foreach (array_chunk(array_map(fn (QuestionStat $s): string => $s->query, $stats), self::IN_CHUNK) as $chunk) {
            $rows = $this->base($site, $from, $to)
                ->whereIn('query', $chunk)
                ->selectRaw('query, url, SUM(impressions) AS imp, SUM(clicks) AS clk, '
                    .'SUM(CASE WHEN position IS NULL THEN 0 ELSE position * impressions END) AS wpos, '
                    .'SUM(CASE WHEN position IS NULL THEN 0 ELSE impressions END) AS wden')
                ->groupBy('query', 'url')
                ->toBase()
                ->get();
            foreach ($rows as $r) {
                $wden = (int) $r->wden;
                $byQuery[(string) $r->query][(string) $r->url] = [
                    'impressions' => (int) $r->imp,
                    'clicks' => (int) $r->clk,
                    'position' => $wden > 0 ? round((float) $r->wpos / $wden, 1) : null,
                ];
            }
        }

        return array_map(fn (QuestionStat $s): QuestionStat => $s->withUrls($byQuery[$s->query] ?? []), $stats);
    }
}
