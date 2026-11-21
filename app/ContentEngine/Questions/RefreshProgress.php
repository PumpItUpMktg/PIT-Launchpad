<?php

namespace App\ContentEngine\Questions;

use App\ContentEngine\Coverage\PostStanding;
use App\Models\Content;
use App\Models\Site;
use App\Support\PublicUrl;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * What Search Console shows for a refreshed post either side of its refresh — the question it was refreshed
 * for (impressions, clicks, impression-weighted position on this URL) and the page as a whole — over EQUAL
 * windows: the days since the refresh (capped at {@see MAX_WINDOW_DAYS}) against the same number of days
 * immediately before it. An observed read, never a claim of cause: GSC's own numbers, re-weighted nowhere.
 */
class RefreshProgress
{
    public const MAX_WINDOW_DAYS = 28;

    /**
     * @return array{
     *   refreshed_at: string, days_since: int, window_days: int, thin: bool,
     *   question: array{query: string, before: array{impressions: int, clicks: int, position: ?float}, after: array{impressions: int, clicks: int, position: ?float}},
     *   page: array{before: array{impressions: int, clicks: int, position: ?float}, after: array{impressions: int, clicks: int, position: ?float}},
     *   metrics: list<array{scope: string, key: string, label: string, before: int|float|null, after: int|float|null, delta: float|null, pct: int|null, direction: string, text: string}>,
     *   summary: array{better: int, worse: int, flat: int, none: int}
     * }|null null when the post carries no refreshed question mark, or the refresh is less than a day old
     */
    public function for(Site $site, Content $post, ?Carbon $now = null): ?array
    {
        $mark = is_array($post->meta['question_refresh'] ?? null) ? $post->meta['question_refresh'] : null;
        if ($mark === null || ! isset($mark['refreshed_at']) || ! is_string($mark['refreshed_at'])) {
            return null;
        }
        $now ??= Carbon::now();
        $refreshedAt = Carbon::parse($mark['refreshed_at'])->startOfDay();
        $daysSince = (int) $refreshedAt->diffInDays($now->copy()->startOfDay(), false);
        if ($daysSince < 1) {
            return null;
        }
        $window = min($daysSince, self::MAX_WINDOW_DAYS);
        // After: the refresh day through today; before: the same number of days ending the day before the refresh.
        $afterFrom = $refreshedAt->toDateString();
        $afterTo = $refreshedAt->copy()->addDays($window)->toDateString();
        $beforeTo = $refreshedAt->copy()->subDay()->toDateString();
        $beforeFrom = $refreshedAt->copy()->subDays($window)->toDateString();

        $url = PublicUrl::forContent($site->domain_url, $post);
        $path = $url !== null ? PostStanding::normalizePath((string) parse_url($url, PHP_URL_PATH)) : null;
        $query = trim((string) ($mark['query'] ?? ''));

        $question = [
            'query' => $query,
            'before' => $this->aggregate($site, 'gsc_url_query_daily', $path, $beforeFrom, $beforeTo, $query),
            'after' => $this->aggregate($site, 'gsc_url_query_daily', $path, $afterFrom, $afterTo, $query),
        ];
        $page = [
            'before' => $this->aggregate($site, 'gsc_url_daily', $path, $beforeFrom, $beforeTo, null),
            'after' => $this->aggregate($site, 'gsc_url_daily', $path, $afterFrom, $afterTo, null),
        ];
        $metrics = [
            self::metric('question', 'position', 'Position', $question['before']['position'], $question['after']['position'], lowerBetter: true),
            self::metric('question', 'impressions', 'Asked', $question['before']['impressions'], $question['after']['impressions']),
            self::metric('question', 'clicks', 'Clicks', $question['before']['clicks'], $question['after']['clicks']),
            self::metric('page', 'position', 'Position', $page['before']['position'], $page['after']['position'], lowerBetter: true),
            self::metric('page', 'impressions', 'Impressions', $page['before']['impressions'], $page['after']['impressions']),
            self::metric('page', 'clicks', 'Clicks', $page['before']['clicks'], $page['after']['clicks']),
        ];
        $summary = ['better' => 0, 'worse' => 0, 'flat' => 0, 'none' => 0];
        foreach ($metrics as $m) {
            $summary[$m['direction']]++;
        }

        return [
            'refreshed_at' => $refreshedAt->toDateString(),
            'days_since' => $daysSince,
            'window_days' => $window,
            'thin' => $daysSince < 7,
            'question' => $question,
            'page' => $page,
            'metrics' => $metrics,
            'summary' => $summary,
        ];
    }

    /**
     * One before → after metric with its signed change, percentage and direction. Position is "lower is
     * better" (a drop in the number is a gain); counts grow. `none` when either side has no data (a position
     * with no impressions), `flat` when unchanged.
     *
     * @return array{scope: string, key: string, label: string, before: int|float|null, after: int|float|null, delta: float|null, pct: int|null, direction: string, text: string}
     */
    public static function metric(string $scope, string $key, string $label, int|float|null $before, int|float|null $after, bool $lowerBetter = false): array
    {
        if ($before === null || $after === null) {
            return ['scope' => $scope, 'key' => $key, 'label' => $label, 'before' => $before, 'after' => $after, 'delta' => null, 'pct' => null, 'direction' => 'none', 'text' => 'no data'];
        }
        $delta = round((float) $after - (float) $before, 1);
        $pct = $before != 0 ? (int) round(($after - $before) / abs($before) * 100) : null;
        if ($delta == 0.0) {
            $direction = 'flat';
            $text = 'no change';
        } else {
            $improved = $lowerBetter ? $delta < 0 : $delta > 0;
            $direction = $improved ? 'better' : 'worse';
            $magnitude = $lowerBetter ? number_format(abs($delta), 1) : number_format(abs($delta));
            $text = ($improved ? '▲ ' : '▼ ').($lowerBetter ? $magnitude.' '.($improved ? 'better' : 'worse') : ($delta > 0 ? '+' : '−').$magnitude.($pct !== null ? ' ('.($pct > 0 ? '+' : ($pct < 0 ? '−' : '')).abs($pct).'%)' : ''));
        }

        return ['scope' => $scope, 'key' => $key, 'label' => $label, 'before' => $before, 'after' => $after, 'delta' => $delta, 'pct' => $pct, 'direction' => $direction, 'text' => $text];
    }

    /**
     * Impressions, clicks and the impression-weighted position for one path (and one query, on the per-query
     * store) between two dates inclusive. Matched by path, so a domain change never loses history.
     *
     * @return array{impressions: int, clicks: int, position: ?float}
     */
    private function aggregate(Site $site, string $table, ?string $path, string $from, string $to, ?string $query): array
    {
        $empty = ['impressions' => 0, 'clicks' => 0, 'position' => null];
        if ($path === null) {
            return $empty;
        }
        $rows = DB::table($table)
            ->where('site_id', $site->id)
            ->whereBetween('date', [$from, $to])
            ->when($query !== null, fn ($q) => $q->where('query', $query))
            ->selectRaw('url,
                SUM(impressions) AS impr,
                SUM(clicks) AS clicks,
                SUM(CASE WHEN position IS NULL THEN 0 ELSE impressions END) AS impr_pos,
                SUM(CASE WHEN position IS NULL THEN 0 ELSE position * impressions END) AS posw')
            ->groupBy('url')
            ->get();

        $impr = 0;
        $clicks = 0;
        $imprPos = 0;
        $posw = 0.0;
        foreach ($rows as $row) {
            if (PostStanding::normalizePath((string) parse_url((string) $row->url, PHP_URL_PATH)) !== $path) {
                continue;
            }
            $impr += (int) $row->impr;
            $clicks += (int) $row->clicks;
            $imprPos += (int) $row->impr_pos;
            $posw += (float) $row->posw;
        }

        return ['impressions' => $impr, 'clicks' => $clicks, 'position' => $imprPos > 0 ? round($posw / $imprPos, 1) : null];
    }
}
