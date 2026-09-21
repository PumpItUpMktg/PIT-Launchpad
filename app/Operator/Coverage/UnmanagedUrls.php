<?php

namespace App\Operator\Coverage;

use App\Enums\ContentStatus;
use App\Metrics\UrlNormalizer;
use App\Models\Content;
use App\Models\GscUrlDaily;
use App\Models\Scopes\SiteScope;
use App\Models\Site;
use App\Publishing\Redirects\CollisionSuffix;
use App\Publishing\Redirects\GscUrlInventory;
use App\Support\PublicUrl;

/**
 * URLs Google holds on this property that Launchpad did not publish — the gap between Search Console's
 * "all known pages" and the Indexing board's count.
 *
 * The board deliberately counts one thing: the pages Launchpad published, the set it can act on. Search
 * Console counts every URL it knows about on the property, which on a WordPress install is a far larger
 * surface — category archives (one per silo, created by the publish pipeline itself), tag archives,
 * paginated archives, author pages, attachment pages, and anything added in WP by hand. Those are real
 * indexed URLs. They are simply not pages anyone wrote.
 *
 * Google does not expose the Page Indexing report through an API, so this cannot mirror that total
 * exactly. What it CAN do is enumerate every URL Google has actually SHOWN — {@see GscUrlInventory}
 * reads that from the retained search-analytics series — and name the ones that are not ours, bucketed by
 * the shape of URL they are. That accounts for the visible half of the difference with real data instead
 * of an assumption about what the rest is.
 *
 * Read-only and HTTP-free.
 */
class UnmanagedUrls
{
    /**
     * @return array{
     *     managed: int,
     *     managed_impressions: int,
     *     managed_clicks: int,
     *     unmanaged: int,
     *     unmanaged_impressions: int,
     *     unmanaged_clicks: int,
     *     position_bands: array<string, array{urls: int, impressions: int, clicks: int}>,
     *     buckets: array<string, array{urls: int, impressions: int}>,
     *     examples: list<array{url: string, bucket: string, impressions: int}>
     * }
     */
    public function for(Site $site, int $examples = 25): array
    {
        $published = Content::withoutGlobalScope(SiteScope::class)
            ->where('site_id', $site->id)
            ->where('status', ContentStatus::Published->value)
            ->get(['id', 'slug', 'kind', 'page_type']);

        $ours = [];
        foreach ($published as $page) {
            $url = PublicUrl::forContent($site->domain_url, $page);
            if ($url !== null) {
                $ours[UrlNormalizer::path($url)] = true;
            }
        }

        // Unmanaged URLs have no Content row, so the shared per-page helper cannot key them — position
        // here is read straight off the series by URL. The weighting rule is the same one PagePositions
        // applies, for the same reason: a quiet day at 3 must not outvote a busy week at 25.
        $positions = $this->positionsByUrl($site);

        $managed = 0;
        $managedImpressions = 0;
        $managedClicks = 0;
        $unmanaged = 0;
        $impressions = 0;
        $clicks = 0;
        // Where the unmanaged traffic actually sits. Impressions alone cannot tell a page-one winner from
        // a page-three also-ran, and the two want opposite treatment: one must not be touched carelessly,
        // the other is the cheapest win on the site.
        $bands = [];
        $buckets = [];
        $rows = [];

        foreach (app(GscUrlInventory::class)->urlTotals($site) as $row) {
            $path = UrlNormalizer::path($row['url']);
            if (isset($ours[$path])) {
                $managed++;
                $managedImpressions += $row['impressions'];
                $managedClicks += $row['clicks'];

                continue;
            }
            $unmanaged++;
            $impressions += $row['impressions'];
            $clicks += $row['clicks'];

            $band = $this->band($positions[UrlNormalizer::url($row['url'])] ?? null);
            $bands[$band]['urls'] = ($bands[$band]['urls'] ?? 0) + 1;
            $bands[$band]['impressions'] = ($bands[$band]['impressions'] ?? 0) + $row['impressions'];
            $bands[$band]['clicks'] = ($bands[$band]['clicks'] ?? 0) + $row['clicks'];

            $bucket = $this->classify($path, $ours);
            $buckets[$bucket]['urls'] = ($buckets[$bucket]['urls'] ?? 0) + 1;
            $buckets[$bucket]['impressions'] = ($buckets[$bucket]['impressions'] ?? 0) + $row['impressions'];
            $rows[] = ['url' => $row['url'], 'bucket' => $bucket, 'impressions' => $row['impressions']];
        }

        uasort($buckets, fn (array $a, array $b): int => $b['urls'] <=> $a['urls']);

        $order = ['1–3', '4–10', '11–20', '21+', 'unknown'];
        uksort($bands, fn (string $a, string $b): int => array_search($a, $order, true) <=> array_search($b, $order, true));

        return [
            'managed' => $managed,
            'managed_impressions' => $managedImpressions,
            'managed_clicks' => $managedClicks,
            'unmanaged' => $unmanaged,
            'unmanaged_impressions' => $impressions,
            'unmanaged_clicks' => $clicks,
            'position_bands' => $bands,
            'buckets' => $buckets,
            'examples' => array_slice($rows, 0, $examples),
        ];
    }

    /**
     * Impression-weighted average position per URL.
     *
     * The URL-keyed sibling of {@see PagePositions}, which keys on Content — these
     * rows are by definition pages we do not have a Content row for, so there is nothing to key on but the
     * URL itself.
     *
     * @return array<string, float>
     */
    private function positionsByUrl(Site $site): array
    {
        $rows = GscUrlDaily::query()->withoutGlobalScope(SiteScope::class)->toBase()
            ->where('site_id', $site->id)
            ->where('impressions', '>', 0)
            ->whereNotNull('position')
            ->selectRaw('url, sum(position * impressions) as weighted, sum(impressions) as impressions')
            ->groupBy('url')
            ->get();

        $out = [];
        foreach ($rows as $row) {
            $impressions = (int) $row->impressions;
            if ($impressions > 0) {
                $out[UrlNormalizer::url((string) $row->url)] = round((float) $row->weighted / $impressions, 1);
            }
        }

        return $out;
    }

    /** The page of results a URL lives on, in the bands that imply different action. */
    private function band(?float $position): string
    {
        return match (true) {
            $position === null => 'unknown',
            $position <= 3.0 => '1–3',
            $position <= 10.0 => '4–10',
            $position <= 20.0 => '11–20',
            default => '21+',
        };
    }

    /**
     * The shape of a URL WordPress generates, from its path alone.
     *
     * The two duplicate buckets are the point. WordPress appends `-2`, `-3`, `-10` when a slug it is asked
     * to create already exists, so a numbered twin is never a coincidence — it is the same title published
     * more than once. Which KIND of duplicate decides who owns the problem:
     *
     *   • "duplicate of a published page" — strip the suffix and the result is a page Launchpad publishes.
     *     That means WordPress refused our slug and served the content somewhere we do not know about, so
     *     the URL we believe in and the URL Google indexed are different strings. Ours to fix.
     *   • "numbered twin (not ours)" — a numbered duplicate whose base is not a page we publish. Legacy
     *     content duplicated before Launchpad, competing with itself.
     *
     * Everything else is deliberately conservative: an unrecognised path reports as "other page" rather
     * than being guessed at, because a wrong label sends someone hunting a problem that is not there.
     *
     * @param  array<string, true>  $ours  normalized paths of the pages Launchpad publishes
     */
    private function classify(string $path, array $ours): string
    {
        $base = $this->stripNumberedSuffix($path);
        if ($base !== null) {
            return isset($ours[$base]) ? 'duplicate of a published page' : 'numbered twin (not ours)';
        }

        return match (true) {
            str_contains($path, '/category/') => 'category archive',
            str_contains($path, '/tag/') => 'tag archive',
            str_contains($path, '/author/') => 'author archive',
            (bool) preg_match('#/page/\d+#', $path) => 'pagination',
            str_contains($path, '/feed') => 'feed',
            str_contains($path, '?') || str_contains($path, '/?') => 'query string',
            (bool) preg_match('#/\d{4}/\d{2}#', $path) => 'date archive',
            $path === '' || $path === '/' => 'home',
            default => 'other page',
        };
    }

    /**
     * The path with WordPress's collision suffix removed, or null when the last segment does not carry one.
     *
     * Only a suffix on the LAST segment counts, and only where something remains in front of it — `/page/2`
     * is pagination, not a twin of `/page`, and a slug that is nothing but a number is not a duplicate of
     * the empty string.
     */
    private function stripNumberedSuffix(string $path): ?string
    {
        $cut = strrpos($path, '/');
        if ($cut === false) {
            return null;
        }
        $segment = substr($path, $cut + 1);
        // A large trailing number is a title, not WordPress's collision counter — the same rule the
        // redirect planner applies, so the two cannot disagree about what a duplicate is.
        $base = CollisionSuffix::strip($segment);
        if ($base === null) {
            return null;
        }
        // /page/2, /blog/page/3 — pagination, already its own shape.
        if (str_ends_with(substr($path, 0, $cut), '/page')) {
            return null;
        }

        return substr($path, 0, $cut + 1).$base;
    }
}
