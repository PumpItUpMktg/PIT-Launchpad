<?php

namespace App\Publishing\Redirects;

use App\Metrics\UrlNormalizer;
use App\Models\Content;
use App\Models\GscUrlDaily;
use App\Models\Scopes\SiteScope;
use App\Models\Site;

/**
 * Checks a redirect plan against what the pages are actually DOING before any of it is applied.
 *
 * {@see LegacyRedirectPlanner} routes by a confidence cascade — numbered duplicate, town, top query, slug
 * overlap. Every rung measures how similar two URLs LOOK. None asks the two questions that decide whether
 * the redirect keeps the traffic:
 *
 *   1. IS THE SUCCESSOR ACTUALLY BETTER? A 301 passes ranking signal, but a weaker page cannot hold a
 *      ranking it could not have earned. Retiring a URL that outperforms its target is how a by-the-book
 *      migration loses traffic.
 *   2. IS ONE PAGE BEING ASKED TO ABSORB A WHOLE SECTION? Google ranks per query. A commercial service
 *      page will not rank for "how to install a sump pump correctly", "installation cost breakdown" and
 *      "plumbing permit requirements" at once, however similar the slugs look. Sump Pump Gurus' plan
 *      pointed 600,131 impressions — 23% of the property — at a single page.
 *
 * Both are ADVISORY in the sense that a human can override them, and BLOCKING in the sense that `--apply`
 * refuses until someone has. An advisory nobody has to read is not a guard.
 *
 * Read-only and HTTP-free.
 */
class RedirectGuard
{
    /** A target taking at least this many sources is absorbing a section, not succeeding one page. */
    private const FUNNEL_SOURCES = 4;

    /** …or this many impressions, however few sources they come from. */
    private const FUNNEL_IMPRESSIONS = 50000;

    /**
     * Impressions below which a source is too quiet for its position to mean anything — comparing a
     * 3-impression page's rank against its target would block a plan over noise.
     */
    private const MIN_IMPRESSIONS_TO_JUDGE = 100;

    /**
     * @param  array{redirect: list<array{from: string, to: string, code: int, impressions: int, reason: string, top_query: ?string}>, ...}  $plan
     * @return array{
     *     outranked: list<array{from: string, to: string, impressions: int, source_position: ?float, target_position: ?float, target_impressions: int, reason: string}>,
     *     funnels: list<array{to: string, sources: int, impressions: int, target_impressions: int, target_position: ?float}>,
     *     blocking: bool
     * }
     */
    public function check(Site $site, array $plan): array
    {
        $metrics = $this->metricsByPath($site);

        $outranked = [];
        $byTarget = [];

        foreach ($plan['redirect'] as $row) {
            $from = UrlNormalizer::path($row['from']);
            $to = UrlNormalizer::path($row['to']);

            $source = $metrics[$from] ?? null;
            $target = $metrics[$to] ?? null;

            $byTarget[$to]['sources'] = ($byTarget[$to]['sources'] ?? 0) + 1;
            $byTarget[$to]['impressions'] = ($byTarget[$to]['impressions'] ?? 0) + (int) $row['impressions'];

            if ($source === null || $source['impressions'] < self::MIN_IMPRESSIONS_TO_JUDGE) {
                continue;
            }

            // A target Google has never shown cannot hold anything. A target that ranks WORSE than the
            // page being retired is being handed a ranking it has not demonstrated it can keep.
            $beaten = $target === null
                || ($target['position'] > $source['position'] && $source['impressions'] > $target['impressions']);

            if ($beaten) {
                $outranked[] = [
                    'from' => $row['from'],
                    'to' => $row['to'],
                    'impressions' => (int) $row['impressions'],
                    'source_position' => $source['position'],
                    'target_position' => $target['position'] ?? null,
                    'target_impressions' => $target['impressions'] ?? 0,
                    'reason' => (string) $row['reason'],
                ];
            }
        }

        $funnels = [];
        foreach ($byTarget as $to => $tally) {
            if ($tally['sources'] < self::FUNNEL_SOURCES && $tally['impressions'] < self::FUNNEL_IMPRESSIONS) {
                continue;
            }
            $target = $metrics[$to] ?? null;
            $funnels[] = [
                'to' => $to,
                'sources' => $tally['sources'],
                'impressions' => $tally['impressions'],
                'target_impressions' => $target['impressions'] ?? 0,
                'target_position' => $target['position'] ?? null,
            ];
        }

        usort($outranked, fn (array $a, array $b): int => $b['impressions'] <=> $a['impressions']);
        usort($funnels, fn (array $a, array $b): int => $b['impressions'] <=> $a['impressions']);

        return [
            'outranked' => $outranked,
            'funnels' => $funnels,
            'blocking' => $outranked !== [] || $funnels !== [],
        ];
    }

    /**
     * Impressions + impression-weighted position for every URL Google has shown, keyed by normalized path.
     *
     * Keyed on PATH rather than content, because both ends of a redirect matter and the source end is by
     * definition a page we do not have a Content row for.
     *
     * @return array<string, array{impressions: int, position: float}>
     */
    private function metricsByPath(Site $site): array
    {
        $rows = GscUrlDaily::query()->withoutGlobalScope(SiteScope::class)->toBase()
            ->where('site_id', $site->id)
            ->where('impressions', '>', 0)
            ->whereNotNull('position')
            ->selectRaw('url, sum(position * impressions) as weighted, sum(impressions) as impressions')
            ->groupBy('url')
            ->get();

        $acc = [];
        foreach ($rows as $row) {
            $path = UrlNormalizer::path((string) $row->url);
            $acc[$path]['weighted'] = ($acc[$path]['weighted'] ?? 0.0) + (float) $row->weighted;
            $acc[$path]['impressions'] = ($acc[$path]['impressions'] ?? 0) + (int) $row->impressions;
        }

        $out = [];
        foreach ($acc as $path => $sums) {
            if ($sums['impressions'] > 0) {
                $out[$path] = [
                    'impressions' => $sums['impressions'],
                    'position' => round($sums['weighted'] / $sums['impressions'], 1),
                ];
            }
        }

        return $out;
    }
}
