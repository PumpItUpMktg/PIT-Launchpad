<?php

namespace App\Operate;

use App\Enums\ContentKind;
use App\Enums\ContentStatus;
use App\Enums\JobStatus;
use App\Enums\PageType;
use App\Models\Content;
use App\Models\Job;
use App\Models\Location;
use App\Models\Scopes\SiteScope;
use App\Models\Site;
use App\Operator\IndexCoverage;
use App\Publishing\Links\InternalLinkGraph;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * The per-tenant index-&-visibility roll-up for the Corrections dashboard: every live URL bucketed by page
 * type (core / service / brick-and-mortar / location-town / blog / jobs), each group carrying its indexed
 * vs not-indexed split (the grey→yellow→green pill rule) and its 28-day Search visibility (impressions /
 * clicks / CTR / blended position), ordered most-visible-first. It answers "which page types are winning
 * and which are dead weight" at a glance, and drills into the individual pages per group.
 *
 * CHEAP by construction: cached URL-Inspection verdicts ({@see IndexCoverage} `live:false` — no API calls),
 * ONE aggregate over the `gsc_url_daily` store for the whole window, and one link-graph build for orphans.
 * GA4 sessions are intentionally omitted (per-page GA4 would fan out to an API call per URL — that stays on
 * the individual cards).
 */
class CoverageDashboard
{
    private const WINDOW_DAYS = 28;

    /** Page types that roll into the "Core" group (everything that isn't Service or Location). */
    private const CORE_TYPES = [PageType::Home, PageType::Utility, PageType::Pillar, PageType::Hub];

    public function __construct(
        private readonly IndexCoverage $index,
        private readonly InternalLinkGraph $graph,
    ) {}

    /**
     * @return array{
     *   window_days: int,
     *   totals: array{total: int, indexed: int, not_indexed: int, submitted: int, not_submitted: int, impressions: int, clicks: int, indexed_pct: int},
     *   groups: list<array<string, mixed>>,
     * }
     */
    public function forSite(Site $site): array
    {
        $verdicts = collect($this->index->audit($site, live: false)['findings'])->keyBy('content_id');
        $gsc = $this->gscByPath($site);
        $graph = $this->graph->build($site);

        $storefrontIds = Location::withoutGlobalScope(SiteScope::class)
            ->where('site_id', $site->id)->where('is_storefront', true)->pluck('id')->flip();

        $groups = $this->emptyGroups();

        Content::withoutGlobalScope(SiteScope::class)
            ->where('site_id', $site->id)
            ->where('status', ContentStatus::Published->value)
            ->get(['id', 'kind', 'page_type', 'slug', 'title', 'location_id', 'parent_location_id', 'indexnow_submitted_at'])
            ->each(function (Content $c) use (&$groups, $verdicts, $gsc, $graph, $storefrontIds): void {
                $key = $this->groupForContent($c, $storefrontIds);
                $stat = $gsc[$this->normalizePath('/'.ltrim((string) $c->slug, '/'))] ?? null;
                $orphan = $graph->inbound((string) $c->id) === [] && ! $graph->isChromeLinked($c);
                $this->accumulate(
                    $groups[$key], (string) $c->id, (string) $c->title,
                    $verdicts->get((string) $c->id), $stat, $c->indexnow_submitted_at !== null, $orphan,
                );
            });

        Job::withoutGlobalScope(SiteScope::class)
            ->where('site_id', $site->id)->where('status', JobStatus::Published->value)
            ->with(['jobTypes', 'city'])
            ->get()
            ->each(function (Job $job) use (&$groups, $verdicts, $gsc): void {
                $stat = $gsc[$this->normalizePath($job->publicPath())] ?? null;
                $this->accumulate(
                    $groups['jobs'], (string) $job->id, $job->publicTitle(),
                    $verdicts->get((string) $job->id), $stat, $job->indexnow_submitted_at !== null, false,
                );
            });

        $rows = array_values(array_filter(array_map([$this, 'finalizeGroup'], $groups), fn (array $g): bool => $g['total'] > 0));
        usort($rows, fn (array $a, array $b): int => $b['impressions'] <=> $a['impressions']); // most-visible first

        return ['window_days' => self::WINDOW_DAYS, 'totals' => $this->totals($rows), 'groups' => $rows];
    }

    /** @return array<string, array<string, mixed>> */
    private function emptyGroups(): array
    {
        $blank = fn (string $label): array => [
            'label' => $label, 'total' => 0, 'indexed' => 0, 'submitted' => 0, 'not_submitted' => 0,
            'canonical_mismatch' => 0, 'orphans' => 0, 'impressions' => 0, 'clicks' => 0, 'impr_pos' => 0, 'posw' => 0.0,
            'pages' => [],
        ];

        return [
            'core' => $blank('Core'),
            'service' => $blank('Service'),
            'brick_mortar' => $blank('Brick & Mortar'),
            'location' => $blank('Location / Town'),
            'blog' => $blank('Blog'),
            'jobs' => $blank('Jobs'),
        ];
    }

    /** @param  Collection<string, mixed>  $storefrontIds */
    private function groupForContent(Content $c, $storefrontIds): string
    {
        if ($c->kind === ContentKind::Post) {
            return 'blog';
        }
        if ($c->page_type === PageType::Service) {
            return 'service';
        }
        if ($c->page_type === PageType::Location) {
            return $c->location_id !== null && $storefrontIds->has($c->location_id) ? 'brick_mortar' : 'location';
        }

        return in_array($c->page_type, self::CORE_TYPES, true) ? 'core' : 'core';
    }

    /**
     * @param  array<string, mixed>  $group
     * @param  array<string, mixed>|null  $verdict
     * @param  array{impr: int, clicks: int, impr_pos: int, posw: float}|null  $stat
     */
    private function accumulate(array &$group, string $id, string $title, ?array $verdict, ?array $stat, bool $indexNow, bool $orphan): void
    {
        $impr = (int) ($stat['impr'] ?? 0);
        $clicks = (int) ($stat['clicks'] ?? 0);
        $indexed = ($verdict['indexed'] ?? false) || $impr > 0; // the pill-green rule
        $known = $verdict !== null && ($verdict['state'] ?? '') !== 'not_inspected';

        $pill = $indexed ? 'indexed' : (($known || $indexNow) ? 'submitted' : 'unsubmitted');

        $group['total']++;
        $group[$indexed ? 'indexed' : ($pill === 'submitted' ? 'submitted' : 'not_submitted')]++;
        if ($verdict['canonical_mismatch'] ?? false) {
            $group['canonical_mismatch']++;
        }
        if ($orphan) {
            $group['orphans']++;
        }
        $group['impressions'] += $impr;
        $group['clicks'] += $clicks;
        $group['impr_pos'] += (int) ($stat['impr_pos'] ?? 0);
        $group['posw'] += (float) ($stat['posw'] ?? 0.0);

        $group['pages'][] = [
            'id' => $id, 'title' => $title !== '' ? $title : 'Untitled', 'pill' => $pill,
            'impressions' => $impr, 'clicks' => $clicks,
            'position' => ($stat['impr_pos'] ?? 0) > 0 ? round((float) $stat['posw'] / (int) $stat['impr_pos'], 1) : null,
        ];
    }

    /**
     * @param  array<string, mixed>  $g
     * @return array<string, mixed>
     */
    private function finalizeGroup(array $g): array
    {
        $pages = $g['pages'];
        usort($pages, fn (array $a, array $b): int => $b['impressions'] <=> $a['impressions']);

        return [
            'label' => $g['label'],
            'total' => $g['total'],
            'indexed' => $g['indexed'],
            'not_indexed' => $g['total'] - $g['indexed'],
            'submitted' => $g['submitted'],
            'not_submitted' => $g['not_submitted'],
            'canonical_mismatch' => $g['canonical_mismatch'],
            'orphans' => $g['orphans'],
            'impressions' => $g['impressions'],
            'clicks' => $g['clicks'],
            'ctr' => $g['impressions'] > 0 ? round($g['clicks'] / $g['impressions'] * 100, 1) : 0.0,
            'avg_position' => $g['impr_pos'] > 0 ? round($g['posw'] / $g['impr_pos'], 1) : null,
            'indexed_pct' => $g['total'] > 0 ? (int) round($g['indexed'] / $g['total'] * 100) : 0,
            'pages' => $pages,
        ];
    }

    /**
     * @param  list<array<string, mixed>>  $rows
     * @return array{total: int, indexed: int, not_indexed: int, submitted: int, not_submitted: int, impressions: int, clicks: int, indexed_pct: int}
     */
    private function totals(array $rows): array
    {
        $sum = fn (string $k): int => (int) array_sum(array_column($rows, $k));
        $total = $sum('total');
        $indexed = $sum('indexed');

        return [
            'total' => $total,
            'indexed' => $indexed,
            'not_indexed' => $total - $indexed,
            'submitted' => $sum('submitted'),
            'not_submitted' => $sum('not_submitted'),
            'impressions' => $sum('impressions'),
            'clicks' => $sum('clicks'),
            'indexed_pct' => $total > 0 ? (int) round($indexed / $total * 100) : 0,
        ];
    }

    /**
     * One aggregate over the 28-day gsc_url_daily window, keyed by normalized path. `impr_pos` is the
     * impressions on rows that carry a position (the blended-position denominator); `posw` is Σ(position ×
     * impressions) (the numerator) — so a group's blended position is Σposw / Σimpr_pos.
     *
     * @return array<string, array{impr: int, clicks: int, impr_pos: int, posw: float}>
     */
    private function gscByPath(Site $site): array
    {
        // Raw query builder (stdClass rows) — a grouped aggregate, not model rows.
        $rows = DB::table('gsc_url_daily')
            ->where('site_id', $site->id)
            ->where('date', '>=', Carbon::now()->subDays(self::WINDOW_DAYS)->toDateString())
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

    /** Canonical path key: leading slash, no trailing slash, lowercased — so `/foo/` and `/foo` match. */
    private function normalizePath(?string $path): string
    {
        $path = '/'.trim((string) $path, '/');

        return mb_strtolower($path);
    }
}
