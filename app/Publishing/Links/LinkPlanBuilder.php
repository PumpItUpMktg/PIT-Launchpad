<?php

namespace App\Publishing\Links;

use App\Enums\ContentKind;
use App\Enums\ContentStatus;
use App\Enums\LinkPlanItemStatus;
use App\Enums\LinkPlanStatus;
use App\Enums\LinkSourceType;
use App\Enums\PageType;
use App\Locations\Distance;
use App\Metrics\UrlNormalizer;
use App\Models\Content;
use App\Models\ContentTown;
use App\Models\CoverageArea;
use App\Models\LinkPlan;
use App\Models\Location;
use App\Models\PageIndexState;
use App\Models\Review;
use App\Models\Scopes\SiteScope;
use App\Models\Site;
use App\Publishing\Blocks\ServiceAreaResolver;
use App\Support\PublicUrl;
use App\Support\TownName;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Builds the "link plan on unlock" — a set of PROPOSED inbound links to the newly-built town pages of a
 * market's just-unlocked tier, from four sources (strongest first):
 *
 *   4. Job/review back-link — the market landing (which surfaces the town's jobs/reviews) links back.
 *   1. Market page — the market landing's town spine links each town (republish).
 *   3. Blog mention — a published post tagged with the town links to it (topical, relevance-based).
 *   2. Mesh — an INDEXED neighbour town links to an UNINDEXED town, CONSTRAINED: at most the target's
 *      N nearest neighbours, never past an inbound floor, never to a top-3 page. Directional by
 *      construction (indexed → unindexed), so no reciprocal pair can form. Proximity is not relevance —
 *      the mesh was 82% of the spine and every reciprocal before this — so it is held hard, not dressed up.
 *
 * The "Areas We Serve" directory is deliberately NOT a source: that page links its towns through its own
 * directory block ({@see ServiceAreaResolver}); proposing a per-town link there just dumped dozens of body
 * links onto a directory page.
 *
 * It only PROPOSES (persists a Proposed {@see LinkPlan} + items); nothing is written until an operator
 * approves and {@see LinkPlanCommitter} runs. Links added to any one source page are capped
 * (`launchpad.link_plan.max_links_per_source`) so no page becomes a link farm.
 */
class LinkPlanBuilder
{
    public function __construct(private readonly InternalLinkGraph $graph) {}

    /** The built link graph for the current run — the source of existing inbound counts (mesh cap). */
    private ?InternalLinkGraph $builtGraph = null;

    /** @var array<string, array<string, true>> target id → set of inbound source ids (existing + proposed) */
    private array $inbound = [];

    /** @var array<string, ?float> content id → blended GSC position (for the top-3 skip); null = untracked */
    private array $position = [];

    /**
     * READ-ONLY preview of the whole link-plan spine: the capped candidate edges {@see propose} WOULD
     * persist across every market × tier, WITHOUT writing anything. The per-source cap is applied PER
     * PLAN (as propose does), and it does NOT compose across plans — so a source that appears in many
     * plans (the Areas page, a market landing) accumulates far past the per-plan cap. This preview keeps
     * that composition visible for the read-only link report; {@see propose} stays the only writer.
     *
     * @return list<array{source: ?string, target: string, type: LinkSourceType, anchor: ?string, market_id: string, tier: ?string}>
     */
    public function previewAll(Site $site): array
    {
        $this->beginRun($site);

        $markets = Location::withoutGlobalScope(SiteScope::class)
            ->where('site_id', $site->id)->get();

        $out = [];
        foreach ($markets as $market) {
            // The tier axis propose() plans against — the four size tiers plus the ungrouped (null) band.
            foreach (['major', 'large', 'medium', 'small', null] as $tier) {
                $targets = $this->targetTowns($site, $market, $tier);
                if ($targets->isEmpty()) {
                    continue;
                }
                foreach ($this->dedupeAndCap($this->candidates($site, $market, $targets)) as $c) {
                    $out[] = $c + ['market_id' => (string) $market->id, 'tier' => $tier];
                }
            }
        }

        return $out;
    }

    public function propose(Site $site, Location $market, ?string $tier): LinkPlan
    {
        $this->beginRun($site);

        $plan = LinkPlan::create([
            'site_id' => $site->id,
            'market_location_id' => $market->id,
            'tier' => $tier,
            'status' => LinkPlanStatus::Proposed,
        ]);

        $targets = $this->targetTowns($site, $market, $tier);
        if ($targets->isEmpty()) {
            return $plan->fresh() ?? $plan;
        }

        $candidates = $this->candidates($site, $market, $targets);
        $capped = $this->dedupeAndCap($candidates);

        foreach ($capped as $item) {
            $plan->items()->create([
                'site_id' => $site->id,
                'source_content_id' => $item['source'],
                'target_content_id' => $item['target'],
                'source_type' => $item['type'],
                'anchor_term' => $item['anchor'],
                'status' => LinkPlanItemStatus::Proposed,
            ]);
        }

        return $plan->fresh(['items']) ?? $plan;
    }

    /**
     * Every candidate (source, target, type, anchor) tuple, before dedupe/cap. Four sources now — the
     * Areas directory is NOT one: an "Areas We Serve" page links its towns through its own directory block
     * ({@see ServiceAreaResolver}), so proposing a per-town link there just injected
     * dozens of body links onto a directory page. Mesh is CONSTRAINED (see below) rather than "every
     * indexed neighbour within the radius", which was 82% of the spine and every reciprocal pair.
     *
     * The stronger, relevance-based sources go first (market/job-review landing, blog) so they count toward
     * a target's inbound before mesh is considered — mesh only FILLS a page the real edges left under the
     * inbound floor, so it feeds the starved rather than circulating among the winners.
     *
     * @param  Collection<int, Content>  $targets
     * @return list<array{source: ?string, target: string, type: LinkSourceType, anchor: ?string}>
     */
    private function candidates(Site $site, Location $market, Collection $targets): array
    {
        $landing = $this->marketLanding($site, $market);
        $indexed = $this->indexedContentIds($site);
        $neighbourPool = $this->indexedTownCentroids($site, $indexed);
        $meshNearest = max(1, (int) config('launchpad.link_plan.mesh_nearest', 3));
        $maxInbound = max(1, (int) config('launchpad.link_plan.max_inbound_per_target', 3));
        $out = [];

        foreach ($targets as $town) {
            $townName = TownName::display((string) $town->title);
            $targetId = (string) $town->id;

            // (1) Market landing → town (spine). Upgraded to (4) Job/review when proof + indexed landing.
            if ($landing !== null) {
                $type = $this->hasLocalProof($site, $town, $market) && isset($indexed[(string) $landing->id])
                    ? LinkSourceType::JobReview
                    : LinkSourceType::Market;
                $out[] = ['source' => (string) $landing->id, 'target' => $targetId, 'type' => $type, 'anchor' => null];
                $this->addInbound($targetId, (string) $landing->id);
            }

            // (3) Published blog posts tagged with the town → town (topical, genuinely relevance-based).
            foreach ($this->blogMentions($site, $town) as $postId) {
                $out[] = ['source' => $postId, 'target' => $targetId, 'type' => LinkSourceType::Blog, 'anchor' => $townName];
                $this->addInbound($targetId, $postId);
            }

            // (2) Mesh — CONSTRAINED. Only to an UNINDEXED, non-top-3 target still under the inbound floor,
            // from its ≤N nearest indexed neighbours. Directional by construction (indexed source → unindexed
            // target) so a reciprocal pair can never form — an unindexed target is never an indexed source —
            // and the inbound cap starves the wheel and feeds the pages that actually need links. Proximity
            // is not relevance, so mesh is held hard rather than dressed up.
            if (! isset($indexed[$targetId]) && ! $this->ranksTop3($targetId)) {
                foreach (array_slice($this->neighbours($town, $neighbourPool), 0, $meshNearest) as $neighbourId) {
                    if (count($this->inboundSet($targetId)) >= $maxInbound) {
                        break;
                    }
                    $out[] = ['source' => $neighbourId, 'target' => $targetId, 'type' => LinkSourceType::Mesh, 'anchor' => $townName];
                    $this->addInbound($targetId, $neighbourId);
                }
            }
        }

        return $out;
    }

    /**
     * Collapse duplicate (source, target) to the strongest type, then cap links added per source page.
     *
     * @param  list<array{source: ?string, target: string, type: LinkSourceType, anchor: ?string}>  $candidates
     * @return list<array{source: ?string, target: string, type: LinkSourceType, anchor: ?string}>
     */
    private function dedupeAndCap(array $candidates): array
    {
        // Keep the strongest (lowest rank) type per (source, target).
        $best = [];
        foreach ($candidates as $c) {
            $key = ($c['source'] ?? '∅').'→'.$c['target'];
            if (! isset($best[$key]) || $c['type']->rank() < $best[$key]['type']->rank()) {
                $best[$key] = $c;
            }
        }

        // Order strongest-first, then cap per source page.
        $ordered = collect($best)->sortBy(fn (array $c): int => $c['type']->rank())->values();
        $cap = max(1, (int) config('launchpad.link_plan.max_links_per_source', 3));
        $perSource = [];
        $out = [];
        foreach ($ordered as $c) {
            $src = $c['source'];
            if ($src !== null) {
                if (($perSource[$src] ?? 0) >= $cap) {
                    continue;
                }
                $perSource[$src] = ($perSource[$src] ?? 0) + 1;
            }
            $out[] = $c;
        }

        return $out;
    }

    /**
     * The market's town pages of the given tier — the newly-built targets.
     *
     * @return Collection<int, Content>
     */
    private function targetTowns(Site $site, Location $market, ?string $tier): Collection
    {
        $tierByTown = $this->tierByTown($site);

        return $this->townPages($site)
            ->filter(fn (Content $c): bool => (string) $c->parent_location_id === (string) $market->id
                && ($tierByTown[TownName::key((string) $c->title)] ?? null) === $tier)
            ->values();
    }

    /** @return Collection<int, Content> all of the site's town pages (page_type=location, town-level) */
    private function townPages(Site $site): Collection
    {
        return Content::withoutGlobalScope(SiteScope::class)
            ->where('site_id', $site->id)
            ->where('kind', ContentKind::Page->value)
            ->where('page_type', PageType::Location->value)
            ->whereNull('location_id')
            ->whereNotNull('parent_location_id')
            ->whereNull('primary_service_id')
            ->get(['id', 'title', 'slug', 'parent_location_id', 'status']);
    }

    /** The market's landing page (page_type=location WITH location_id set to the market Location). */
    private function marketLanding(Site $site, Location $market): ?Content
    {
        return Content::withoutGlobalScope(SiteScope::class)
            ->where('site_id', $site->id)
            ->where('kind', ContentKind::Page->value)
            ->where('page_type', PageType::Location->value)
            ->where('location_id', $market->id)
            ->where('status', ContentStatus::Published->value)
            ->first(['id', 'slug', 'wp_post_id']);
    }

    /**
     * Set up per-run state: the live link graph (existing inbound counts), the blended-position map (the
     * top-3 skip), and a fresh inbound tally + centroid memo. Called once per {@see propose} (one plan) and
     * once per {@see previewAll} (the whole site) — so the mesh inbound cap composes across a preview.
     */
    private function beginRun(Site $site): void
    {
        $this->builtGraph = $this->graph->build($site);
        $this->position = $this->blendedPositions($site, $this->builtGraph);
        $this->inbound = [];
        $this->centroidCache = [];
    }

    /** The running inbound-source set for a target, seeded lazily from the live graph's existing inbound. */
    private function inboundSet(string $targetId): array
    {
        if (! isset($this->inbound[$targetId])) {
            $existing = $this->builtGraph !== null ? $this->builtGraph->inbound($targetId) : [];
            $this->inbound[$targetId] = array_fill_keys($existing, true);
        }

        return $this->inbound[$targetId];
    }

    /** Record an inbound source for a target (deduped) — a candidate edge, or existing graph inbound. */
    private function addInbound(string $targetId, string $sourceId): void
    {
        $this->inboundSet($targetId);
        $this->inbound[$targetId][$sourceId] = true;
    }

    /** Whether a target already ranks top 3 (GSC blended position ≤ 3) — a page that needs no more links. */
    private function ranksTop3(string $contentId): bool
    {
        $pos = $this->position[$contentId] ?? null;

        return $pos !== null && $pos <= 3.0;
    }

    /**
     * Blended GSC position per content id over the trailing 28 days (Σ position×impressions / Σ impressions
     * on positioned rows), matched by normalized path — the rank source the top-3 skip reads. Null when the
     * page has no positioned impressions.
     *
     * @return array<string, ?float>
     */
    private function blendedPositions(Site $site, InternalLinkGraph $graph): array
    {
        $rows = DB::table('gsc_url_daily')
            ->where('site_id', $site->id)
            ->where('date', '>=', Carbon::now()->subDays(27)->toDateString())
            ->selectRaw('url,
                SUM(CASE WHEN position IS NULL THEN 0 ELSE impressions END) AS impr_pos,
                SUM(CASE WHEN position IS NULL THEN 0 ELSE position * impressions END) AS posw')
            ->groupBy('url')
            ->get();

        $byPath = [];
        foreach ($rows as $row) {
            $path = UrlNormalizer::path((string) parse_url((string) $row->url, PHP_URL_PATH));
            $byPath[$path] ??= ['impr_pos' => 0, 'posw' => 0.0];
            $byPath[$path]['impr_pos'] += (int) $row->impr_pos;
            $byPath[$path]['posw'] += (float) $row->posw;
        }

        $out = [];
        foreach ($graph->pages as $id => $page) {
            $path = UrlNormalizer::path(PublicUrl::forContent($site->domain_url, $page) ?? '/'.ltrim((string) $page->slug, '/'));
            $stat = $byPath[$path] ?? null;
            $out[(string) $id] = $stat !== null && $stat['impr_pos'] > 0 ? $stat['posw'] / $stat['impr_pos'] : null;
        }

        return $out;
    }

    /**
     * Published town pages that are INDEXED, with their coverage-area centroid — the mesh candidate pool.
     *
     * @param  array<string, true>  $indexed
     * @return list<array{id: string, lat: float, lng: float}>
     */
    private function indexedTownCentroids(Site $site, array $indexed): array
    {
        $centroids = $this->townCentroids($site);
        $pool = [];
        foreach ($this->townPages($site) as $page) {
            if ($page->status !== ContentStatus::Published || ! isset($indexed[(string) $page->id])) {
                continue;
            }
            $c = $centroids[TownName::key((string) $page->title)] ?? null;
            if ($c !== null) {
                $pool[] = ['id' => (string) $page->id, 'lat' => $c['lat'], 'lng' => $c['lng']];
            }
        }

        return $pool;
    }

    /**
     * The indexed neighbour town-page ids within the radius of the target town (nearest first).
     *
     * @param  list<array{id: string, lat: float, lng: float}>  $pool
     * @return list<string>
     */
    private function neighbours(Content $town, array $pool): array
    {
        $centroids = $this->centroidCache;
        $c = $centroids[TownName::key((string) $town->title)] ?? null;
        if ($c === null) {
            return [];
        }

        $radius = (float) config('launchpad.link_plan.neighbour_radius_miles', 20.0);
        $near = [];
        foreach ($pool as $n) {
            if ($n['id'] === (string) $town->id) {
                continue;
            }
            $miles = Distance::miles($c['lat'], $c['lng'], $n['lat'], $n['lng']);
            if ($miles <= $radius) {
                $near[$n['id']] = $miles;
            }
        }
        asort($near);

        return array_keys($near);
    }

    /**
     * Published blog posts tagged with the town (via content_towns normalized-name join).
     *
     * @return list<string>
     */
    private function blogMentions(Site $site, Content $town): array
    {
        return ContentTown::query()
            ->where('site_id', $site->id)
            ->where('town', TownName::key((string) $town->title))
            ->whereHas('content', fn ($q) => $q->withoutGlobalScope(SiteScope::class)
                ->where('kind', ContentKind::Post->value)
                ->where('status', ContentStatus::Published->value))
            ->pluck('content_id')
            ->map(fn ($id): string => (string) $id)
            ->all();
    }

    /**
     * Does this town carry a published review (local proof the indexed landing surfaces)? Keyed on the
     * item-3 town-tagged reviews. Captured jobs use a different geo model (job_city / jittered coords) and
     * are a follow-up; reviews are the proof signal here.
     */
    private function hasLocalProof(Site $site, Content $town, Location $market): bool
    {
        return Review::withoutGlobalScope(SiteScope::class)
            ->where('site_id', $site->id)
            ->where('status', 'published')
            ->where('town', TownName::display((string) $town->title))
            ->exists();
    }

    /** @return array<string, true> the site's indexed (PASS) content ids */
    private function indexedContentIds(Site $site): array
    {
        $ids = PageIndexState::query()
            ->where('site_id', $site->id)
            ->where('index_verdict', 'PASS')
            ->whereNotNull('content_id')
            ->pluck('content_id');
        $set = [];
        foreach ($ids as $id) {
            $set[(string) $id] = true;
        }

        return $set;
    }

    /** @var array<string, array{lat: float, lng: float}> memoized normalized town => centroid */
    private array $centroidCache = [];

    /** @return array<string, array{lat: float, lng: float}> */
    private function townCentroids(Site $site): array
    {
        if ($this->centroidCache !== []) {
            return $this->centroidCache;
        }
        foreach (CoverageArea::withoutGlobalScope(SiteScope::class)->where('site_id', $site->id)->whereNotNull('lat')->whereNotNull('lng')->get(['name', 'lat', 'lng']) as $area) {
            $this->centroidCache[TownName::key((string) $area->name)] = ['lat' => (float) $area->lat, 'lng' => (float) $area->lng];
        }

        return $this->centroidCache;
    }

    /** @return array<string, string> normalized town name => size_tier value */
    private function tierByTown(Site $site): array
    {
        $map = [];
        foreach (CoverageArea::withoutGlobalScope(SiteScope::class)->where('site_id', $site->id)->get(['name', 'size_tier']) as $area) {
            if (is_string($area->size_tier) && $area->size_tier !== '') {
                $map[TownName::key((string) $area->name)] = $area->size_tier;
            }
        }

        return $map;
    }
}
