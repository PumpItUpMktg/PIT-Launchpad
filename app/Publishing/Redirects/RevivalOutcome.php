<?php

namespace App\Publishing\Redirects;

use App\Enums\ContentStatus;
use App\Metrics\UrlNormalizer;
use App\Models\Content;
use App\Models\GscUrlDaily;
use App\Models\GscUrlQueryDaily;
use App\Models\Scopes\SiteScope;
use App\Models\Site;
use App\Support\PublicUrl;
use Illuminate\Support\Carbon;

/**
 * Did a revived post hold what the pages it replaced were earning?
 *
 * A revival rewrites a legacy family and 301s every original onto the new post. From that moment the
 * old URLs are gone, and the only way to know whether the rewrite was a good idea is to compare the
 * family's Search traffic BEFORE publish against the new post's traffic AFTER — the same number of days
 * on each side, so a post that is five days old is compared against the family's last five days, not
 * against a month it has not had.
 *
 * Three numbers per revival, each answering a different question:
 *
 *   • BEFORE vs AFTER impressions, clicks, position — did the traffic carry over?
 *   • RESIDUAL — what the OLD URLs still earn after publish. Should fall to nothing. If it does not,
 *     the 301 is not landing or Google has not re-crawled, and the "after" number is being read too soon.
 *   • QUERY COVERAGE — which of the queries the family was briefed on the new post now ranks for. This
 *     is the direct test of the brief: a post that holds the headline query and loses the eleven behind
 *     it can show a fine impression count and still have thrown most of the cluster away.
 *
 * Every verdict is a comparison, never an attribution. A rewrite published the week a competitor
 * folded reads the same as one that was simply better.
 */
class RevivalOutcome
{
    /** Below this many days after publish, Google has barely seen the new post and no verdict is honest. */
    private const MIN_DAYS = 7;

    /**
     * @return list<array{
     *     title: string, url: ?string, published_on: ?string, elapsed_days: int, days_compared: int,
     *     verdict: string,
     *     before: array{impressions: int, clicks: int, position: ?float},
     *     after: array{impressions: int, clicks: int, position: ?float},
     *     ratio: ?float, residual: int,
     *     queries: array{brief: int, ranking: int, lost: list<string>}
     * }>
     */
    public function for(Site $site, int $days = 28): array
    {
        $posts = Content::withoutGlobalScope(SiteScope::class)
            ->where('site_id', $site->id)
            ->where('status', ContentStatus::Published->value)
            ->whereNotNull('meta->revived_from_urls')
            ->whereNotNull('published_at')
            ->orderBy('published_at')
            ->get();

        $out = [];
        foreach ($posts as $post) {
            $row = $this->outcome($site, $post, max(1, $days));
            if ($row !== null) {
                $out[] = $row;
            }
        }

        return $out;
    }

    /**
     * @return array{
     *     title: string, url: ?string, published_on: ?string, elapsed_days: int, days_compared: int,
     *     verdict: string,
     *     before: array{impressions: int, clicks: int, position: ?float},
     *     after: array{impressions: int, clicks: int, position: ?float},
     *     ratio: ?float, residual: int,
     *     queries: array{brief: int, ranking: int, lost: list<string>}
     * }|null
     */
    private function outcome(Site $site, Content $post, int $days): ?array
    {
        $meta = is_array($post->meta) ? $post->meta : [];
        $oldPaths = [];
        foreach ((array) ($meta['revived_from_urls'] ?? []) as $u) {
            if (is_string($u) && $u !== '') {
                $oldPaths[] = UrlNormalizer::path($u);
            }
        }
        if ($oldPaths === [] || $post->published_at === null) {
            return null;
        }

        $published = $post->published_at->copy()->startOfDay();
        $elapsed = (int) $published->diffInDays(Carbon::today());
        // Like for like: a five-day-old post is measured against the family's last five days.
        $n = max(1, min($days, $elapsed));

        $newUrl = PublicUrl::forContent($site->domain_url, $post);
        $newPath = $newUrl === null ? null : UrlNormalizer::path($newUrl);

        $before = $this->totals($site, $oldPaths, $published->copy()->subDays($n), $published->copy()->subDay());
        $after = $newPath === null
            ? ['impressions' => 0, 'clicks' => 0, 'position' => null]
            : $this->totals($site, [$newPath], $published, $published->copy()->addDays($n - 1));
        $residual = $this->totals($site, $oldPaths, $published, $published->copy()->addDays($n - 1))['impressions'];

        $briefQueries = $this->briefQueries($meta);
        $ranking = $newPath === null ? [] : $this->queriesRanking($site, $newPath, $published, $published->copy()->addDays($n - 1));
        $lost = array_values(array_filter($briefQueries, fn (string $q): bool => ! isset($ranking[mb_strtolower($q)])));

        $ratio = $before['impressions'] > 0 ? round($after['impressions'] / $before['impressions'], 2) : null;

        return [
            'title' => (string) $post->title,
            'url' => $newUrl,
            'published_on' => $published->toDateString(),
            'elapsed_days' => $elapsed,
            'days_compared' => $n,
            'verdict' => $this->verdict($elapsed, $before['impressions'], $ratio),
            'before' => $before,
            'after' => $after,
            'ratio' => $ratio,
            'residual' => $residual,
            'queries' => [
                'brief' => count($briefQueries),
                'ranking' => count($briefQueries) - count($lost),
                'lost' => $lost,
            ],
        ];
    }

    /**
     * Bands, not a score. The ratio is printed beside the word so nobody has to trust the word.
     */
    private function verdict(int $elapsed, int $beforeImpressions, ?float $ratio): string
    {
        if ($elapsed < self::MIN_DAYS) {
            return 'too_early';
        }
        if ($beforeImpressions === 0 || $ratio === null) {
            return 'no_baseline';
        }

        return match (true) {
            $ratio >= 1.1 => 'gained',
            $ratio >= 0.85 => 'held',
            $ratio >= 0.5 => 'softened',
            default => 'lost',
        };
    }

    /**
     * Impressions, clicks and impression-weighted position over a set of paths in a date window.
     *
     * @param  list<string>  $paths
     * @return array{impressions: int, clicks: int, position: ?float}
     */
    private function totals(Site $site, array $paths, Carbon $from, Carbon $to): array
    {
        $urls = $this->urlForms($site, $paths);
        if ($urls === [] || $to->lessThan($from)) {
            return ['impressions' => 0, 'clicks' => 0, 'position' => null];
        }

        $row = GscUrlDaily::query()->withoutGlobalScope(SiteScope::class)->toBase()
            ->where('site_id', $site->id)
            ->whereIn('url', $urls)
            // Half-open on the upper bound: the column is a real date on Postgres but a datetime string
            // under SQLite, and "<= 2026-09-11" silently drops "2026-09-11 00:00:00". "< the next day"
            // is right on both.
            ->where('date', '>=', $from->toDateString())
            ->where('date', '<', $to->copy()->addDay()->toDateString())
            ->selectRaw('coalesce(sum(impressions),0) as impressions, coalesce(sum(clicks),0) as clicks, '
                .'sum(case when position is null then 0 else position * impressions end) as weighted, '
                .'sum(case when position is null then 0 else impressions end) as positioned')
            ->first();

        $impressions = (int) ($row->impressions ?? 0);
        $positioned = (int) ($row->positioned ?? 0);

        return [
            'impressions' => $impressions,
            'clicks' => (int) ($row->clicks ?? 0),
            'position' => $positioned > 0 ? round((float) $row->weighted / $positioned, 1) : null,
        ];
    }

    /**
     * Queries the new post earned impressions for in the window, lowercased, as a lookup set.
     *
     * @return array<string, true>
     */
    private function queriesRanking(Site $site, string $path, Carbon $from, Carbon $to): array
    {
        $urls = $this->urlForms($site, [$path]);
        if ($urls === [] || $to->lessThan($from)) {
            return [];
        }

        $rows = GscUrlQueryDaily::query()->withoutGlobalScope(SiteScope::class)->toBase()
            ->where('site_id', $site->id)
            ->whereIn('url', $urls)
            ->where('date', '>=', $from->toDateString())
            ->where('date', '<', $to->copy()->addDay()->toDateString())
            ->where('impressions', '>', 0)
            ->distinct()
            ->pluck('query');

        $out = [];
        foreach ($rows as $q) {
            $out[mb_strtolower(trim((string) $q))] = true;
        }

        return $out;
    }

    /**
     * The queries the family was briefed on — the full list when the multi-query brief exists, else the
     * single winning query older candidates carried.
     *
     * @param  array<string, mixed>  $meta
     * @return list<string>
     */
    private function briefQueries(array $meta): array
    {
        $out = [];
        foreach ((array) ($meta['revived_queries'] ?? []) as $q) {
            $query = is_array($q) ? ($q['query'] ?? null) : $q;
            if (is_string($query) && trim($query) !== '') {
                $out[] = trim($query);
            }
        }
        if ($out === [] && is_string($meta['revived_query'] ?? null) && trim($meta['revived_query']) !== '') {
            $out[] = trim($meta['revived_query']);
        }

        return array_values(array_unique($out));
    }

    /**
     * Both slash forms of each path as absolute URLs — Search Console stores whichever the site's
     * permalinks settled on, and which that is is not ours to assume.
     *
     * @param  list<string>  $paths
     * @return list<string>
     */
    private function urlForms(Site $site, array $paths): array
    {
        $root = rtrim((string) $site->domain_url, '/');
        if ($root === '') {
            return [];
        }
        $out = [];
        foreach ($paths as $path) {
            $bare = '/'.trim($path, '/');
            $out[] = $root.$bare;
            $out[] = $root.$bare.'/';
        }

        return array_values(array_unique($out));
    }
}
