<?php

namespace App\Publishing;

use App\Enums\ContentKind;
use App\Enums\ContentStatus;
use App\Enums\PageType;
use App\Models\Content;
use App\Models\Scopes\SiteScope;
use App\Models\Site;
use App\Publishing\Blocks\ServiceAreaResolver;

/**
 * Computes what the town-page coverage repush WOULD produce, per tenant — the "report the count before
 * executing" data for the nearest-neighbour coverage change. It runs the REAL production selector
 * ({@see ServiceAreaResolver::neighbours()}) over every published location page, so the numbers are computed
 * from the actual code the render uses, not re-derived from a proxy: the same input, the same distance math,
 * the same publish-gated link resolution.
 *
 * The headline is the town-page neighbour-count DISTRIBUTION (6 / 3–5 / 1–2 / dropped), with drops split into
 * `unanchored` (no resolvable centroid — shrinks as `launchpad:anchor-town-pages` runs) and `no_range` (a
 * real coverage-density fact: an anchored town with no served town within the radius). Hub (market) pages are
 * counted too — they repush as well, and their county list now links — but the distribution is town-specific.
 *
 * A true byte-diff of the rendered section against the live WordPress page is NOT included: the companion
 * plugin exposes no post_content read endpoint (only /content/diagnose, which returns post state). The change
 * is render-only and deterministic — every town page's coverage moves from the parent's block to its own
 * neighbours — so the distribution captures the meaningful variation; the per-page byte-delta would only
 * confirm "every page changed."
 */
final class TownCoveragePreview
{
    public function __construct(private readonly ServiceAreaResolver $areas) {}

    /**
     * The whole portfolio, one entry per site with at least one published location page, brand-first.
     *
     * @return list<array<string, mixed>>
     */
    public function report(): array
    {
        $out = [];
        foreach (Site::withoutGlobalScopes()->orderBy('brand_name')->get() as $site) {
            $entry = $this->forSite($site);
            if ($entry['total'] > 0) {
                $out[] = $entry;
            }
        }

        return $out;
    }

    /**
     * One site's preview: hub/town split, the town neighbour-count distribution, the two drop reasons, the
     * repush count (all published location pages), and per-town rows (fewest neighbours first, so the drops
     * and thin cases lead).
     *
     * The `dist` keys are non-numeric on purpose (n6 / n3_5 / n1_2 / drop) so the array shape stays
     * string-keyed; the command maps them to the "6 / 3–5 / 1–2" labels for display.
     *
     * @return array{site: Site, brand: string, hub: int, town: int, total: int, dist: array{n6: int, n3_5: int, n1_2: int, drop: int}, unanchored: int, no_range: int, pages: list<array{slug: string, count: int, dropped: bool, anchored: bool, neighbours: list<string>}>}
     */
    public function forSite(Site $site): array
    {
        $rows = Content::withoutGlobalScope(SiteScope::class)
            ->where('site_id', $site->id)
            ->where('kind', ContentKind::Page->value)
            ->where('page_type', PageType::Location->value)
            ->where('status', ContentStatus::Published->value)
            ->get(['slug', 'title', 'geo_id', 'location_id', 'parent_location_id']);

        $hub = 0;
        $town = 0;
        $dist = ['n6' => 0, 'n3_5' => 0, 'n1_2' => 0, 'drop' => 0];
        $unanchored = 0;
        $noRange = 0;
        $pages = [];

        foreach ($rows as $content) {
            if ($content->location_id !== null) {
                $hub++;

                continue; // hubs keep the county list (now linked); the distribution is town-specific
            }
            if ($content->parent_location_id === null) {
                continue; // neither pin — not a composed location page
            }

            $town++;
            $result = $this->areas->neighbours(
                (string) $site->id,
                (string) $content->parent_location_id,
                $content->geo_id,
                (string) $content->title,
            );
            $neighbours = array_map(fn (array $t): string => $t['label'], $result['towns']);
            $count = count($neighbours);

            if ($count === 0) {
                $dist['drop']++;
                if ($result['anchored']) {
                    $noRange++;
                } else {
                    $unanchored++;
                }
            } elseif ($count >= 6) {
                $dist['n6']++;
            } elseif ($count >= 3) {
                $dist['n3_5']++;
            } else {
                $dist['n1_2']++;
            }

            $pages[] = [
                'slug' => (string) $content->slug,
                'count' => $count,
                'dropped' => $count === 0,
                'anchored' => (bool) $result['anchored'],
                'neighbours' => $neighbours,
            ];
        }

        usort($pages, fn (array $a, array $b): int => $a['count'] <=> $b['count']);

        return [
            'site' => $site,
            'brand' => trim((string) $site->brand_name),
            'hub' => $hub,
            'town' => $town,
            'total' => $hub + $town,
            'dist' => $dist,
            'unanchored' => $unanchored,
            'no_range' => $noRange,
            'pages' => $pages,
        ];
    }
}
