<?php

namespace App\Publishing\Links;

use App\Enums\ContentKind;
use App\Enums\ContentStatus;
use App\Jobs\BoostReleasedPages;
use App\Jobs\PublishContent;
use App\Models\Content;
use App\Models\PageIndexState;
use App\Models\Scopes\SiteScope;
use App\Models\Silo;
use App\Models\Site;
use App\Publishing\Redirects\GscUrlInventory;
use Illuminate\Support\Collection;

/**
 * Operator-run indexing accelerator: help a site's NEWLY-published pages that Google hasn't indexed yet
 * get discovered, by adding a controlled "Related" link to each from a few ALREADY-INDEXED pages, then
 * re-pushing those sources so Google follows the fresh crawl path (a page reachable only from other new
 * pages waits on the sitemap; a link from a page Google already crawls indexes materially faster).
 *
 * The whole-site, any-page-type, index-state-driven counterpart to {@see InboundLinkBooster} (which is
 * post-only, silo-scoped, GSC-impression-ranked, and runs as a publish hook). This one:
 *
 *  - TARGETS new unindexed pages — published pages with a `wp_post_id`, `published_at` within the window,
 *    and NO `page_index_states` PASS row ({@see PageIndexState::isIndexed()}).
 *  - SOURCES from confirmed-indexed pages (`index_verdict=PASS`), the target's own silo preferred, filling
 *    with other indexed pages so a new page always earns a few inbound links.
 *  - PLACES a controlled related block ({@see LinkInjector::appendRelated} — reversible, idempotent), never
 *    an in-body edit.
 *  - BOUNDED — `max_targets` per run, `max_sources_per_target`, and `max_links_per_source` (anti-bloat, so
 *    no page turns into a link farm), all from config `launchpad.internal_linking.index_boost`.
 *  - SAFE — locked / locally-edited sources are skipped; the re-push is the normal idempotent-by-ULID
 *    {@see PublishContent}, which also fires the IndexNow ping. Nothing runs automatically — an operator
 *    invokes it (launchpad:boost-indexing).
 */
final class IndexBooster
{
    public function __construct(
        private readonly LinkInjector $injector,
        private readonly GscUrlInventory $inventory,
    ) {}

    /**
     * Add inbound "Related" links to the site's new unindexed pages from its indexed pages.
     *
     * @return array{targets: int, sources_available: int, links: int, sources_repushed: int, applied: bool, details: list<array{target: string, path: string, sources: list<string>}>}
     */
    public function boost(Site $site, bool $apply = true): array
    {
        $window = max(1, (int) config('launchpad.internal_linking.index_boost.window_days', 30));
        $maxTargets = max(1, (int) config('launchpad.internal_linking.index_boost.max_targets', 25));

        $targets = Content::withoutGlobalScope(SiteScope::class)
            ->where('site_id', $site->id)
            ->where('kind', ContentKind::Page->value)
            ->where('status', ContentStatus::Published->value)
            ->whereNotNull('wp_post_id')
            ->whereNotIn('id', $this->indexedIds($site)->all())
            ->where('published_at', '>=', now()->subDays($window))
            ->orderByDesc('published_at')
            ->limit($maxTargets)
            ->get();

        return $this->boostTargets($site, $targets, $apply);
    }

    /**
     * Boost EXACT targets — the pages a publish-drip release just put live ({@see BoostReleasedPages}).
     * A target already indexed, or not live, is skipped.
     *
     * @param  Collection<int, Content>  $targets
     * @return array{targets: int, sources_available: int, links: int, sources_repushed: int, applied: bool, details: list<array{target: string, path: string, sources: list<string>}>}
     */
    public function boostTargets(Site $site, Collection $targets, bool $apply = true): array
    {
        $maxSources = max(1, (int) config('launchpad.internal_linking.index_boost.max_sources_per_target', 3));
        $maxPerSource = max(1, (int) config('launchpad.internal_linking.index_boost.max_links_per_source', 3));

        $indexedIds = $this->indexedIds($site);
        $sources = $indexedIds->isEmpty() ? collect() : Content::withoutGlobalScope(SiteScope::class)
            ->where('site_id', $site->id)
            ->where('kind', ContentKind::Page->value)
            ->where('status', ContentStatus::Published->value)
            ->whereNotNull('wp_post_id')
            ->whereIn('id', $indexedIds->all())
            ->get()
            ->reject(fn (Content $c): bool => $c->isPublishProtected());
        $indexedSet = array_fill_keys($indexedIds->map(fn ($id): string => (string) $id)->all(), true);
        $targets = $targets->filter(fn (Content $t): bool => $t->wp_post_id !== null && ! isset($indexedSet[(string) $t->id]))->values();
        $impressions = $this->impressionsByPath($site);
        $pillars = $this->pillarIds($site);

        $linksBySource = [];   // source id => links added this run (the per-source cap)
        $repush = [];          // source id => true (distinct sources to re-push)
        $links = 0;
        $details = [];

        foreach ($targets as $target) {
            $path = $this->path($target);
            $label = $this->label($target);
            if ($path === '/' || $label === '') {
                continue;
            }

            $used = [];
            foreach ($this->rankedSources($sources, $target, $impressions, $pillars) as $source) {
                if (count($used) >= $maxSources) {
                    break;
                }
                $sid = (string) $source->id;
                if (($linksBySource[$sid] ?? 0) >= $maxPerSource || $this->linksTo($source, $path)) {
                    continue;
                }

                if ($apply) {
                    if (! $this->injector->appendRelated($source, $label, $path)) {
                        continue;   // idempotent no-op (already linked) — don't count or re-push
                    }
                    $repush[$sid] = true;
                }
                $linksBySource[$sid] = ($linksBySource[$sid] ?? 0) + 1;
                $used[] = $sid;
                $links++;
            }

            if ($used !== []) {
                $details[] = ['target' => (string) $target->title, 'path' => $path, 'sources' => $used];
            }
        }

        if ($apply) {
            foreach (array_keys($repush) as $sid) {
                PublishContent::dispatch($sid);
            }
        }

        return [
            'targets' => $targets->count(),
            'sources_available' => $sources->count(),
            'links' => $links,
            'sources_repushed' => count($repush),
            'applied' => $apply,
            'details' => $details,
        ];
    }

    /**
     * Indexed source pages for a target, the HIGHEST-RANKING RELEVANT ones first: the pages that most
     * naturally point at it (a town's own office hub; a page's silo pillar; sibling towns under the same
     * office; same-silo pages), and within each tier the ones Google already shows most (Search Console
     * impressions, the page booster's yardstick), then the rest. A page with no impressions is never
     * preferred over one with some. Never the target itself.
     *
     * @param  Collection<int, Content>  $sources
     * @param  array<string, int>  $impressions  normalized path → impressions
     * @param  array<string, true>  $pillars  silo pillar content ids
     * @return list<Content>
     */
    private function rankedSources(Collection $sources, Content $target, array $impressions, array $pillars): array
    {
        $siloId = $target->matched_silo_id ?? $target->silo_id;
        $office = $target->parent_location_id !== null ? (string) $target->parent_location_id : null;
        $tier = function (Content $s) use ($siloId, $office, $pillars): int {
            if ($office !== null && $s->location_id !== null && (string) $s->location_id === $office) {
                return 0;   // the town's own office hub
            }
            if ($siloId !== null && isset($pillars[(string) $s->id]) && $s->silo_id === $siloId) {
                return 1;   // the silo's pillar page
            }
            if ($office !== null && $s->parent_location_id !== null && (string) $s->parent_location_id === $office) {
                return 2;   // a sibling town under the same office
            }
            if ($siloId !== null && $s->silo_id === $siloId) {
                return 3;   // same silo
            }

            return 4;
        };

        return $sources
            ->reject(fn (Content $s): bool => (string) $s->id === (string) $target->id)
            ->sortBy(fn (Content $s): array => [$tier($s), -($impressions[$this->normalizePath((string) $s->slug)] ?? 0)])
            ->values()
            ->all();
    }

    /** @return Collection<int, string> */
    private function indexedIds(Site $site): Collection
    {
        return PageIndexState::withoutGlobalScope(SiteScope::class)
            ->where('site_id', $site->id)
            ->where('index_verdict', 'PASS')
            ->whereNotNull('content_id')
            ->pluck('content_id')->unique()->values();
    }

    /** @return array<string, int> normalized path → lifetime impressions (empty when Search Console has nothing) */
    private function impressionsByPath(Site $site): array
    {
        $out = [];
        foreach ($this->inventory->urlTotals($site) as $row) {
            $out[$this->normalizePath($row['url'])] = $row['impressions'];
        }

        return $out;
    }

    /** @return array<string, true> */
    private function pillarIds(Site $site): array
    {
        return array_fill_keys(
            Silo::withoutGlobalScope(SiteScope::class)->where('site_id', $site->id)->whereNotNull('pillar_content_id')->pluck('pillar_content_id')->map(fn ($id): string => (string) $id)->all(),
            true,
        );
    }

    private function normalizePath(string $value): string
    {
        $parsed = parse_url(trim($value), PHP_URL_PATH);
        $path = is_string($parsed) ? $parsed : $value;

        return mb_strtolower(trim($path, '/'));
    }

    /** The target's link path — leading slash (mirrors {@see InboundLinkBooster}). */
    private function path(Content $target): string
    {
        return '/'.ltrim((string) $target->slug, '/');
    }

    /** Anchor label for the related block — the target's ranked keyword, else its title. */
    private function label(Content $target): string
    {
        $keyword = trim((string) ($target->targetKeyword->query ?? ''));

        return $keyword !== '' ? $keyword : trim((string) $target->title);
    }

    /** Whether a source page already contains a link to this path (a fast pre-skip + dry-run signal). */
    private function linksTo(Content $source, string $path): bool
    {
        $needle = 'href="'.$path.'"';
        $body = is_string($source->body) ? $source->body : '';
        if (str_contains($body, $needle)) {
            return true;
        }
        $slots = is_array($source->slot_payload) ? json_encode($source->slot_payload) : '';

        return is_string($slots) && str_contains($slots, $needle);
    }
}
