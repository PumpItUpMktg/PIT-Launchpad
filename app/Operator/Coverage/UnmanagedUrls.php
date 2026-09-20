<?php

namespace App\Operator\Coverage;

use App\Enums\ContentStatus;
use App\Metrics\UrlNormalizer;
use App\Models\Content;
use App\Models\Scopes\SiteScope;
use App\Models\Site;
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
     *     unmanaged: int,
     *     unmanaged_impressions: int,
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

        $managed = 0;
        $managedImpressions = 0;
        $unmanaged = 0;
        $impressions = 0;
        $buckets = [];
        $rows = [];

        foreach (app(GscUrlInventory::class)->urlTotals($site) as $row) {
            $path = UrlNormalizer::path($row['url']);
            if (isset($ours[$path])) {
                $managed++;
                $managedImpressions += $row['impressions'];

                continue;
            }
            $unmanaged++;
            $impressions += $row['impressions'];
            $bucket = $this->classify($path, $ours);
            $buckets[$bucket]['urls'] = ($buckets[$bucket]['urls'] ?? 0) + 1;
            $buckets[$bucket]['impressions'] = ($buckets[$bucket]['impressions'] ?? 0) + $row['impressions'];
            $rows[] = ['url' => $row['url'], 'bucket' => $bucket, 'impressions' => $row['impressions']];
        }

        uasort($buckets, fn (array $a, array $b): int => $b['urls'] <=> $a['urls']);

        return [
            'managed' => $managed,
            'managed_impressions' => $managedImpressions,
            'unmanaged' => $unmanaged,
            'unmanaged_impressions' => $impressions,
            'buckets' => $buckets,
            'examples' => array_slice($rows, 0, $examples),
        ];
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
        if (! preg_match('/^(.+)-\d+$/', $segment, $m)) {
            return null;
        }
        // /page/2, /blog/page/3 — pagination, already its own shape.
        if (str_ends_with(substr($path, 0, $cut), '/page')) {
            return null;
        }

        return substr($path, 0, $cut + 1).$m[1];
    }
}
