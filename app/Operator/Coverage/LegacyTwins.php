<?php

namespace App\Operator\Coverage;

use App\Metrics\UrlNormalizer;
use App\Models\Site;
use App\Publishing\Redirects\CollisionSuffix;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * The numbered twins Launchpad did NOT publish — the same legacy post live under `/foo/`, `/foo-2/`,
 * `/foo-8/` — grouped by the title they are copies of, with the earner named per group and the
 * consolidation each group would need spelled out. Report-only: nothing here writes.
 *
 * WordPress appends `-2`, `-3` when a slug it is asked to create already exists, so a numbered twin is
 * never a coincidence; a chain to `-8` is the same article published eight times, and Google is splitting
 * one article's equity across copies and spending crawl budget on them. Neither post has a Content row,
 * so {@see DuplicatePostResolver} (our posts) cannot see them — this is its legacy-lane sibling.
 *
 * KEEPER — the EARNER, judged on the recent window first (a copy that earned last year and nothing since
 * is not the page Google is sending people to today), lifetime as the fallback when the window is quiet:
 *   - exactly one member tops the window → keep it (`earner`);
 *   - window all zero, exactly one tops lifetime → keep it (`earner-lifetime`);
 *   - a tie at the top (either measure) → `ambiguous-earner`: a human chooses, never the rule — by pinning
 *     the keeper's path (`operator-override`).
 * A group whose base is a page WE publish is not a legacy twin — that is {@see SlugCollisions} — and is
 * left out here so the two reports cannot both claim it.
 *
 * HTTP-free: `gsc_url_daily` only. "Last seen" (the newest day a member earned an impression) is the
 * liveness signal; the consolidation itself verifies serving before removing anything.
 */
final class LegacyTwins
{
    /**
     * @return array{
     *     groups: list<array{
     *         base: string, resolvable: bool, reason: string, impressions: int, window_impressions: int,
     *         keeper: ?array{path: string, url: string, numbered: bool, impressions: int, window_impressions: int, clicks: int, position: ?float, last_seen: ?string},
     *         losers: list<array{path: string, url: string, numbered: bool, impressions: int, window_impressions: int, clicks: int, position: ?float, last_seen: ?string, from: string, to: string}>,
     *         members: list<array{path: string, url: string, numbered: bool, impressions: int, window_impressions: int, clicks: int, position: ?float, last_seen: ?string}>
     *     }>,
     *     totals: array{groups: int, twins: int, resolvable: int, ambiguous: int, impressions: int, window_impressions: int, redirects: int},
     *     window_days: int
     * }
     */
    /**
     * `$keep` pins a keeper per group by PATH (`/foo-3`) — how an `ambiguous-earner` group is settled by a
     * human. A pinned path overrides the impression rule for its group (`operator-override`); two pins in
     * one group is an `override-conflict` (reported, never resolved); a pin matching no member is ignored
     * (the command surfaces it).
     *
     * @param  list<string>  $keep
     */
    public function for(Site $site, int $days = 28, array $keep = []): array
    {
        $pins = [];
        foreach ($keep as $path) {
            $pins[UrlNormalizer::path($path)] = true;
        }
        $ours = app(DiscoveredUrls::class)->ours($site);
        $cutoff = Carbon::now()->subDays($days)->toDateString();
        $base = rtrim((string) $site->domain_url, '/');

        $rows = DB::table('gsc_url_daily')
            ->where('site_id', $site->id)
            ->groupBy('url')
            ->selectRaw(
                'url, sum(impressions) as impressions, sum(clicks) as clicks, max(date) as last_seen, '
                .'sum(case when date >= ? then impressions else 0 end) as window_impressions, '
                .'sum(case when position is not null then position * impressions else 0 end) as weighted, '
                .'sum(case when position is not null then impressions else 0 end) as positioned',
                [$cutoff],
            )
            ->get();

        // One member per normalized path (both slash forms fold together), keyed for the grouping pass.
        $members = [];
        foreach ($rows as $r) {
            $path = UrlNormalizer::path((string) $r->url);
            if (isset($ours[$path])) {
                continue;
            }
            $m = $members[$path] ?? ['path' => $path, 'url' => $base.$path.'/', 'numbered' => CollisionSuffix::stripPath($path) !== null, 'impressions' => 0, 'window_impressions' => 0, 'clicks' => 0, 'weighted' => 0.0, 'positioned' => 0, 'last_seen' => null];
            $m['impressions'] += (int) $r->impressions;
            $m['window_impressions'] += (int) $r->window_impressions;
            $m['clicks'] += (int) $r->clicks;
            $m['weighted'] += (float) $r->weighted;
            $m['positioned'] += (int) $r->positioned;
            $seen = $r->last_seen !== null ? Carbon::parse((string) $r->last_seen)->toDateString() : null;
            $m['last_seen'] = $m['last_seen'] === null || ($seen !== null && $seen > $m['last_seen']) ? $seen : $m['last_seen'];
            $members[$path] = $m;
        }

        // Group: every numbered twin under its base; the base itself joins when Google has shown it.
        $groups = [];
        foreach ($members as $path => $m) {
            if (! $m['numbered']) {
                continue;
            }
            $root = (string) CollisionSuffix::stripPath($path);
            if (isset($ours[$root])) {
                continue; // a twin of OUR page — SlugCollisions' question, not a legacy twin
            }
            $groups[$root] ??= [];
            $groups[$root][$path] = true;
        }

        $out = [];
        $totals = ['groups' => 0, 'twins' => 0, 'resolvable' => 0, 'ambiguous' => 0, 'impressions' => 0, 'window_impressions' => 0, 'redirects' => 0];
        foreach ($groups as $root => $twinPaths) {
            $paths = array_keys($twinPaths);
            if (isset($members[$root])) {
                array_unshift($paths, $root);
            }
            $list = array_map(fn (string $p): array => $this->member($members[$p]), $paths);

            [$keeper, $reason] = $this->keeper($list);
            $pinned = array_values(array_filter($list, fn (array $m): bool => isset($pins[$m['path']])));
            if (count($pinned) > 1) {
                [$keeper, $reason] = [null, 'override-conflict'];
            } elseif (count($pinned) === 1) {
                [$keeper, $reason] = [$pinned[0], 'operator-override'];
            }
            $losers = [];
            if ($keeper !== null) {
                foreach ($list as $m) {
                    if ($m['path'] !== $keeper['path']) {
                        $losers[] = $m + ['from' => $m['path'], 'to' => $keeper['path']];
                    }
                }
            }
            $impressions = array_sum(array_column($list, 'impressions'));
            $window = array_sum(array_column($list, 'window_impressions'));

            $out[] = [
                'base' => $root,
                'resolvable' => $keeper !== null,
                'reason' => $reason,
                'impressions' => $impressions,
                'window_impressions' => $window,
                'keeper' => $keeper,
                'losers' => $losers,
                'members' => $list,
            ];
            $totals['groups']++;
            $totals['twins'] += count($twinPaths);
            $totals[$keeper !== null ? 'resolvable' : 'ambiguous']++;
            $totals['impressions'] += $impressions;
            $totals['window_impressions'] += $window;
            $totals['redirects'] += count($losers);
        }

        usort($out, fn (array $a, array $b): int => $b['impressions'] <=> $a['impressions']);

        return ['groups' => $out, 'totals' => $totals, 'window_days' => $days];
    }

    /**
     * @param  array{path: string, url: string, numbered: bool, impressions: int, window_impressions: int, clicks: int, weighted: float, positioned: int, last_seen: ?string}  $m
     * @return array{path: string, url: string, numbered: bool, impressions: int, window_impressions: int, clicks: int, position: ?float, last_seen: ?string}
     */
    private function member(array $m): array
    {
        return [
            'path' => $m['path'],
            'url' => $m['url'],
            'numbered' => $m['numbered'],
            'impressions' => $m['impressions'],
            'window_impressions' => $m['window_impressions'],
            'clicks' => $m['clicks'],
            'position' => $m['positioned'] > 0 ? round($m['weighted'] / $m['positioned'], 1) : null,
            'last_seen' => $m['last_seen'],
        ];
    }

    /**
     * The earner, or null with the reason the rule cannot name one.
     *
     * @param  list<array{path: string, url: string, numbered: bool, impressions: int, window_impressions: int, clicks: int, position: ?float, last_seen: ?string}>  $list
     * @return array{0: ?array{path: string, url: string, numbered: bool, impressions: int, window_impressions: int, clicks: int, position: ?float, last_seen: ?string}, 1: string}
     */
    private function keeper(array $list): array
    {
        foreach (['window_impressions' => 'earner', 'impressions' => 'earner-lifetime'] as $measure => $reason) {
            $top = max(array_column($list, $measure));
            if ($top <= 0) {
                continue;
            }
            $leaders = array_values(array_filter($list, fn (array $m): bool => $m[$measure] === $top));

            return count($leaders) === 1 ? [$leaders[0], $reason] : [null, 'ambiguous-earner'];
        }

        return [null, 'no-impressions'];
    }
}
