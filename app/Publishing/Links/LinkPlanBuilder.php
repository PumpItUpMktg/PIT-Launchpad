<?php

namespace App\Publishing\Links;

use App\Enums\ContentKind;
use App\Enums\ContentStatus;
use App\Enums\LinkPlanItemStatus;
use App\Enums\LinkPlanStatus;
use App\Enums\LinkSourceType;
use App\Enums\PageType;
use App\Enums\StandardPageType;
use App\Locations\Distance;
use App\Models\Content;
use App\Models\ContentTown;
use App\Models\CoverageArea;
use App\Models\LinkPlan;
use App\Models\Location;
use App\Models\PageIndexState;
use App\Models\Review;
use App\Models\Scopes\SiteScope;
use App\Models\Site;
use App\Support\TownName;
use Illuminate\Support\Collection;

/**
 * Builds the "link plan on unlock" — a set of PROPOSED inbound links to the newly-built town pages of a
 * market's just-unlocked tier, drawn from the five sources (strongest first):
 *
 *   4. Job/review back-link — the market landing (which surfaces the town's jobs/reviews) links back.
 *   1. Market page — the market landing's town spine links each town (republish).
 *   2. Neighbouring town — an INDEXED town page within the neighbour radius links across (a mesh, not a hub).
 *   3. Blog mention — a published post tagged with the town links to it.
 *   5. Areas We Serve — the directory page links every town (republish).
 *
 * It only PROPOSES (persists a Proposed {@see LinkPlan} + items); nothing is written until an operator
 * approves and {@see LinkPlanCommitter} runs. Links added to any one source page are capped
 * (`launchpad.link_plan.max_links_per_source`) so no page becomes a link farm.
 */
class LinkPlanBuilder
{
    public function propose(Site $site, Location $market, ?string $tier): LinkPlan
    {
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
     * Every candidate (source, target, type, anchor) tuple across the five sources, before dedupe/cap.
     *
     * @param  Collection<int, Content>  $targets
     * @return list<array{source: ?string, target: string, type: LinkSourceType, anchor: ?string}>
     */
    private function candidates(Site $site, Location $market, Collection $targets): array
    {
        $landing = $this->marketLanding($site, $market);
        $areas = $this->areasPage($site);
        $indexed = $this->indexedContentIds($site);
        $neighbourPool = $this->indexedTownCentroids($site, $indexed);
        $out = [];

        foreach ($targets as $town) {
            $townName = TownName::display((string) $town->title);
            $targetId = (string) $town->id;

            // (1) Market landing → town (spine republish). Upgraded to (4) Job/review below when proof exists.
            if ($landing !== null) {
                $type = $this->hasLocalProof($site, $town, $market) && isset($indexed[(string) $landing->id])
                    ? LinkSourceType::JobReview
                    : LinkSourceType::Market;
                $out[] = ['source' => (string) $landing->id, 'target' => $targetId, 'type' => $type, 'anchor' => null];
            }

            // (2) Neighbouring INDEXED town pages within the radius → town (a mesh).
            foreach ($this->neighbours($town, $neighbourPool) as $neighbourId) {
                $out[] = ['source' => $neighbourId, 'target' => $targetId, 'type' => LinkSourceType::Mesh, 'anchor' => $townName];
            }

            // (3) Published blog posts tagged with the town → town.
            foreach ($this->blogMentions($site, $town) as $postId) {
                $out[] = ['source' => $postId, 'target' => $targetId, 'type' => LinkSourceType::Blog, 'anchor' => $townName];
            }

            // (5) Areas We Serve → town (spine republish).
            if ($areas !== null) {
                $out[] = ['source' => (string) $areas->id, 'target' => $targetId, 'type' => LinkSourceType::Areas, 'anchor' => null];
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

    /** The site's Areas-We-Serve directory page. */
    private function areasPage(Site $site): ?Content
    {
        return Content::withoutGlobalScope(SiteScope::class)
            ->where('site_id', $site->id)
            ->where('standard_type', StandardPageType::AreasWeServe->value)
            ->where('status', ContentStatus::Published->value)
            ->first(['id', 'slug', 'wp_post_id']);
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
