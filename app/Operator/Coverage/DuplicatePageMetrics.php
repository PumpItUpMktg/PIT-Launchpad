<?php

namespace App\Operator\Coverage;

use App\Enums\ContentKind;
use App\Enums\ContentStatus;
use App\Enums\PageType;
use App\Models\Content;
use App\Models\PageIndexState;
use App\Models\Scopes\SiteScope;
use App\Models\Site;
use App\Support\PublicUrl;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * The GSC-metrics view of LIVE duplicate location pages — the data the operator needs to decide, per pair,
 * whether a canonical or a 301 is right, and in WHICH direction. {@see ReportDuplicateTownPagesCommand}
 * classifies the shapes; this one attaches Search Console impressions + blended position to each side so
 * "which page actually holds the authority" is a fact, not a guess.
 *
 * It surfaces two live-duplicate shapes under one grouping:
 *   - LANDING ↔ TOWN — a market landing ("/hoboken-nj/", `location_id` set — the GBP location page) and a
 *     self-named town page under it ("/hoboken-nj/hoboken-nj/", `parent_location_id` = that location). Both
 *     are assembled from the same kit/entities today, so they compete for one query.
 *   - TOWN ↔ TOWN — two published town pages for the same town under one parent (the Buckingham
 *     "buckingham-pa" / "buckingham-pa-2" shape).
 * Both collapse to one key: `(coalesce(location_id, parent_location_id), townKey(title))` — the physical
 * town, parent-aware so two markets' same-named towns are never conflated.
 *
 * READ-ONLY. Position is GSC's native impression-weighted blended position over the window (default 28d):
 * Σ(position × impressions) / Σ(impressions on rows carrying a position). The member with the impressions
 * is the ranking page — so when the landing is trimmed to a location card, authority must flow to whichever
 * side this shows as the earner (a self-canonical on a gutted page throws it away).
 */
final class DuplicatePageMetrics
{
    private const WINDOW_DAYS = 28;

    /**
     * @return list<array{
     *   town: string, scope_location_id: ?string,
     *   members: list<array{content_id:string, role:string, title:string, slug:string, url:?string,
     *     impressions:int, clicks:int, position:?float, index:string, top_impressions:bool}>
     * }>
     */
    public function report(Site $site, int $days = self::WINDOW_DAYS): array
    {
        $pages = Content::withoutGlobalScope(SiteScope::class)
            ->where('site_id', $site->id)
            ->where('kind', ContentKind::Page->value)
            ->where('page_type', PageType::Location->value)
            ->where('status', ContentStatus::Published->value)
            ->get(['id', 'site_id', 'title', 'slug', 'location_id', 'parent_location_id']);

        $groups = $pages
            ->groupBy(fn (Content $c): string => ($c->location_id ?? $c->parent_location_id ?? '∅').'|'.$this->townKey((string) $c->title))
            ->filter(fn (Collection $g): bool => $g->count() > 1);

        if ($groups->isEmpty()) {
            return [];
        }

        $domain = $site->domain_url;
        $gsc = $this->gscByPath($site, max(1, $days));

        $out = [];
        foreach ($groups as $group) {
            $members = [];
            foreach ($group as $page) {
                $url = PublicUrl::forContent($domain, $page);
                $metrics = $url !== null ? ($gsc[$this->normalizePath((string) parse_url($url, PHP_URL_PATH))] ?? null) : null;

                $members[] = [
                    'content_id' => (string) $page->id,
                    'role' => $page->location_id !== null ? 'landing' : 'town',
                    'title' => (string) $page->title,
                    'slug' => (string) $page->slug,
                    'url' => $url,
                    'impressions' => (int) ($metrics['impr'] ?? 0),
                    'clicks' => (int) ($metrics['clicks'] ?? 0),
                    'position' => ($metrics !== null && $metrics['impr_pos'] > 0) ? round($metrics['posw'] / $metrics['impr_pos'], 1) : null,
                    'index' => $this->indexVerdict((string) $page->id),
                    'top_impressions' => false,
                ];
            }

            // Flag the earner (most impressions) — the page whose authority a canonical/301 must preserve.
            $maxImpr = max(array_map(fn (array $m): int => $m['impressions'], $members));
            if ($maxImpr > 0) {
                foreach ($members as $i => $m) {
                    $members[$i]['top_impressions'] = $m['impressions'] === $maxImpr;
                }
            }

            $out[] = [
                'town' => trim((string) preg_replace('/,\s*[A-Za-z]{2}\.?$/', '', (string) $group->first()->title)),
                'scope_location_id' => $group->first()->location_id ?? $group->first()->parent_location_id,
                'members' => $members,
            ];
        }

        return $out;
    }

    /**
     * One aggregate over the gsc_url_daily window, keyed by normalized path (mirrors CoverageDashboard).
     * `impr_pos` = impressions on rows carrying a position (the blended denominator); `posw` = Σ(position ×
     * impressions) (the numerator) — a path's blended position is posw / impr_pos.
     *
     * @return array<string, array{impr:int, clicks:int, impr_pos:int, posw:float}>
     */
    private function gscByPath(Site $site, int $days): array
    {
        $rows = DB::table('gsc_url_daily')
            ->where('site_id', $site->id)
            ->where('date', '>=', Carbon::now()->subDays($days)->toDateString())
            ->selectRaw('url,
                SUM(impressions) AS impr,
                SUM(clicks) AS clicks,
                SUM(CASE WHEN position IS NULL THEN 0 ELSE impressions END) AS impr_pos,
                SUM(CASE WHEN position IS NULL THEN 0 ELSE position * impressions END) AS posw')
            ->groupBy('url')
            ->get();

        $out = [];
        foreach ($rows as $row) {
            $path = $this->normalizePath((string) parse_url((string) $row->url, PHP_URL_PATH));
            if (! isset($out[$path])) {
                $out[$path] = ['impr' => 0, 'clicks' => 0, 'impr_pos' => 0, 'posw' => 0.0];
            }
            $out[$path]['impr'] += (int) $row->impr;
            $out[$path]['clicks'] += (int) $row->clicks;
            $out[$path]['impr_pos'] += (int) $row->impr_pos;
            $out[$path]['posw'] += (float) $row->posw;
        }

        return $out;
    }

    /** Three-state index verdict from the durable table (mirrors the Live board + the duplicate-town report). */
    private function indexVerdict(string $contentId): string
    {
        $row = PageIndexState::withoutGlobalScope(SiteScope::class)->where('content_id', $contentId)->first();
        if ($row === null) {
            return 'not checked';
        }

        return $row->isIndexed()
            ? 'indexed'
            : ($row->coverage_state !== null && $row->coverage_state !== '' ? "not indexed ({$row->coverage_state})" : 'not indexed');
    }

    /** Canonical path key: leading slash, no trailing slash, lowercased — so `/foo/` and `/foo` match. */
    private function normalizePath(?string $path): string
    {
        return mb_strtolower('/'.trim((string) $path, '/'));
    }

    /** Normalize a town name for matching (drop a trailing ", ST", lower) — mirrors the sweeper + CLI. */
    private function townKey(string $name): string
    {
        return mb_strtolower(trim((string) preg_replace('/,\s*[A-Za-z]{2}\.?$/', '', trim($name))));
    }
}
