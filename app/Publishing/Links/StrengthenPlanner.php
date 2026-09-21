<?php

namespace App\Publishing\Links;

use App\Enums\ContentStatus;
use App\Enums\LinkPlanItemStatus;
use App\Enums\LinkPlanStatus;
use App\Enums\LinkSourceType;
use App\Models\Content;
use App\Models\Keyword;
use App\Models\LinkPlan;
use App\Models\LinkPlanItem;
use App\Models\Scopes\SiteScope;
use App\Models\Site;
use App\Operator\Coverage\PagePositions;
use App\Support\PublicUrl;
use App\Support\TownName;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Inbound links for pages that are already FOUND but not yet WINNING — the half of internal linking the
 * platform did not have.
 *
 * Every other source in {@see LinkPlanBuilder} exists to get a new page discovered: the mesh requires an
 * UNINDEXED target, {@see IndexBooster} targets unindexed pages, {@see InboundLinkBooster} fires on a
 * fresh post. A page sitting at position 18 with real search volume behind it is past all of them — it has
 * been found, it is simply losing. Nothing proposed a single link for it.
 *
 * This inverts the target filter and keeps every constraint that made the mesh safe:
 *
 *  - STRIKING DISTANCE ONLY. The target's impression-weighted position must fall inside the configured
 *    band (default 8–20). Above it the page is a winner and needs no help — the same judgement as the
 *    existing top-3 skip, just drawn wider. Below it, a link is not what is wrong.
 *  - DEMAND REQUIRED. The target's keyword must carry real volume. Lifting a page nobody searches for
 *    from 18 to 8 changes nothing, and spending link equity on it takes equity from a page it would move.
 *  - RELATED ONLY. Sources come from the target's own silo, its hub, or a sibling under that same hub —
 *    for a town page that is the market's other towns, for a service page its silo. Topical or structural
 *    relation, never "any strong page on the site".
 *  - NEVER A LINK THAT ALREADY EXISTS. {@see InternalLinkGraph} derives the silo grid and the location
 *    grid as real edges, so a service page already links its siblings and a hub already links its spokes.
 *    Those come back from `linksTo()` and are skipped, which is why this proposes almost nothing for
 *    service pages and real work for town pages — the latter get no derived silo edges at all.
 *  - STRONGEST SOURCES FIRST, by real Search-Console impressions, so the link arrives on a crawl path
 *    Google already walks.
 *  - NO RECIPROCALS. If the target already links to a candidate source, that source is skipped. The mesh
 *    got this free by being directional (indexed → unindexed); here both ends are indexed, so it is
 *    checked explicitly against the live link graph.
 *  - CAPPED both ways: at most `max_inbound_per_target` inbound INCLUDING what the page already has, and
 *    the per-source ceiling the committer already honours, so no page becomes a link farm.
 *
 * Targets are ordered by opportunity (the §5 score, falling back to volume), so the scarce thing — links
 * a real page can absorb before it looks spammy — goes where it converts.
 *
 * It only PROPOSES. Nothing is written to WordPress until an operator approves and {@see LinkPlanCommitter}
 * runs, exactly as with the unlock spine.
 */
final class StrengthenPlanner
{
    public function __construct(
        private readonly InternalLinkGraph $graph,
        private readonly PagePositions $positions,
    ) {}

    /**
     * Read-only: the links {@see propose} WOULD make, with the evidence for each.
     *
     * @return list<array{
     *     target: string, title: string, url: ?string, position: float, volume: ?int, opportunity: ?float,
     *     existing_inbound: int, sources: list<array{id: string, title: string, impressions: int}>
     * }>
     */
    public function preview(Site $site, int $limit = 25): array
    {
        $graph = $this->graph->build($site);

        $published = Content::withoutGlobalScope(SiteScope::class)
            ->where('site_id', $site->id)
            ->where('status', ContentStatus::Published->value)
            ->get(['id', 'title', 'slug', 'kind', 'page_type', 'silo_id', 'parent_content_id', 'target_keyword_id']);

        $metrics = $this->positions->for($site, $published);
        $keywords = $this->keywordScores($site, $published);

        [$low, $high] = $this->band();
        $minVolume = max(0, (int) config('launchpad.link_plan.strengthen.min_volume', 20));
        $maxInbound = max(1, (int) config('launchpad.link_plan.strengthen.max_inbound_per_target', 3));

        $candidates = [];
        foreach ($published as $page) {
            $id = (string) $page->id;
            $position = $metrics[$id]['position'] ?? null;
            if ($position === null || $position < $low || $position > $high) {
                continue;
            }

            $score = $page->target_keyword_id !== null ? ($keywords[(string) $page->target_keyword_id] ?? null) : null;
            if ($score === null || ($score['volume'] ?? 0) < $minVolume) {
                continue;
            }

            $existing = count($graph->inbound($id));
            $room = $maxInbound - $existing;
            if ($room <= 0) {
                continue;
            }

            $sources = $this->sourcesFor($page, $published, $metrics, $graph, $room);
            if ($sources === []) {
                continue;
            }

            $candidates[] = [
                'target' => $id,
                'title' => (string) $page->title,
                'url' => PublicUrl::forContent($site->domain_url, $page),
                'position' => $position,
                'volume' => $score['volume'],
                'opportunity' => $score['opportunity'],
                'existing_inbound' => $existing,
                'sources' => $sources,
            ];
        }

        // Opportunity first, then volume, then the page closest to breaking through.
        usort($candidates, fn (array $a, array $b): int => [
            -($b['opportunity'] ?? 0.0), -($b['volume'] ?? 0), $a['position'],
        ] <=> [
            -($a['opportunity'] ?? 0.0), -($a['volume'] ?? 0), $b['position'],
        ]);

        return array_slice($candidates, 0, max(1, $limit));
    }

    /**
     * Persist the preview as a Proposed {@see LinkPlan}, or null when nothing qualifies.
     *
     * The plan carries no market or tier — a strengthen pass is not tied to an unlock, it is driven by
     * where the site is currently losing.
     */
    public function propose(Site $site, int $limit = 25): ?LinkPlan
    {
        $preview = $this->preview($site, $limit);
        if ($preview === []) {
            return null;
        }

        return DB::transaction(function () use ($site, $preview): LinkPlan {
            $plan = LinkPlan::withoutGlobalScopes()->create([
                'site_id' => $site->id,
                'market_location_id' => null,
                'tier' => null,
                'status' => LinkPlanStatus::Proposed,
            ]);

            foreach ($preview as $row) {
                foreach ($row['sources'] as $source) {
                    LinkPlanItem::withoutGlobalScopes()->create([
                        'link_plan_id' => $plan->id,
                        'site_id' => $site->id,
                        'source_content_id' => $source['id'],
                        'target_content_id' => $row['target'],
                        'source_type' => LinkSourceType::Strengthen,
                        'anchor_term' => TownName::display($row['title']),
                        'status' => LinkPlanItemStatus::Proposed,
                    ]);
                }
            }

            return $plan->fresh(['items']) ?? $plan;
        });
    }

    /**
     * The strongest same-silo (or hub) sources that may link to this target, strongest first.
     *
     * @param  Collection<int, Content>  $published
     * @param  array<string, array{position: float, impressions: int}>  $metrics
     * @return list<array{id: string, title: string, impressions: int}>
     */
    private function sourcesFor(Content $target, Collection $published, array $metrics, InternalLinkGraph $graph, int $room): array
    {
        $targetId = (string) $target->id;
        $pool = [];

        foreach ($published as $page) {
            $id = (string) $page->id;
            if ($id === $targetId) {
                continue;
            }
            $sameSilo = $target->silo_id !== null && $page->silo_id === $target->silo_id;
            $isHub = $target->parent_content_id !== null && $id === (string) $target->parent_content_id;
            // A sibling under the same hub: for a town page this is the market's other towns, which is
            // where the neighbouring-town link the surfaces recommend actually comes from.
            $isSibling = $target->parent_content_id !== null
                && $page->parent_content_id !== null
                && (string) $page->parent_content_id === (string) $target->parent_content_id;
            if (! $sameSilo && ! $isHub && ! $isSibling) {
                continue;
            }
            // A source has to be a page Google already walks, or the link arrives on no crawl path at all.
            $impressions = $metrics[$id]['impressions'] ?? 0;
            if ($impressions <= 0) {
                continue;
            }
            if ($graph->linksTo($id, $targetId)) {
                continue;   // already links there
            }
            if ($graph->linksTo($targetId, $id)) {
                continue;   // would form a reciprocal pair
            }

            // The hub outranks a sibling of equal traffic: it is the page the group is organised around.
            $pool[] = ['id' => $id, 'title' => (string) $page->title, 'impressions' => $impressions, 'hub' => $isHub];
        }

        usort($pool, fn (array $a, array $b): int => [$b['hub'], $b['impressions']] <=> [$a['hub'], $a['impressions']]);

        return array_map(
            fn (array $s): array => ['id' => $s['id'], 'title' => $s['title'], 'impressions' => $s['impressions']],
            array_slice($pool, 0, $room),
        );
    }

    /**
     * Volume + opportunity per target keyword.
     *
     * @param  Collection<int, Content>  $published
     * @return array<string, array{volume: ?int, opportunity: ?float}>
     */
    private function keywordScores(Site $site, Collection $published): array
    {
        $ids = $published->pluck('target_keyword_id')->filter()->unique()->values();
        if ($ids->isEmpty()) {
            return [];
        }

        $out = [];
        foreach (Keyword::withoutGlobalScope(SiteScope::class)
            ->where('site_id', $site->id)
            ->whereIn('id', $ids->all())
            ->get(['id', 'volume', 'opportunity_score']) as $keyword) {
            $out[(string) $keyword->id] = [
                'volume' => $keyword->volume === null ? null : (int) $keyword->volume,
                'opportunity' => $keyword->opportunity_score === null ? null : (float) $keyword->opportunity_score,
            ];
        }

        return $out;
    }

    /**
     * The striking-distance band. Its floor is the existing top-3 skip drawn wider: a page inside the top
     * few needs no help, and a page past the ceiling is not losing for want of an internal link.
     *
     * @return array{0: float, 1: float}
     */
    private function band(): array
    {
        $low = (float) config('launchpad.link_plan.strengthen.position_min', 8);
        $high = (float) config('launchpad.link_plan.strengthen.position_max', 20);

        return $high >= $low ? [$low, $high] : [$high, $low];
    }
}
