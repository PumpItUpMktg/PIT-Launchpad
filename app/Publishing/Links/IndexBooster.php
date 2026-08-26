<?php

namespace App\Publishing\Links;

use App\Enums\ContentKind;
use App\Enums\ContentStatus;
use App\Jobs\PublishContent;
use App\Models\Content;
use App\Models\PageIndexState;
use App\Models\Scopes\SiteScope;
use App\Models\Site;
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
    public function __construct(private readonly LinkInjector $injector) {}

    /**
     * Add inbound "Related" links to the site's new unindexed pages from its indexed pages.
     *
     * @return array{targets: int, sources_available: int, links: int, sources_repushed: int, applied: bool, details: list<array{target: string, path: string, sources: list<string>}>}
     */
    public function boost(Site $site, bool $apply = true): array
    {
        $window = max(1, (int) config('launchpad.internal_linking.index_boost.window_days', 30));
        $maxTargets = max(1, (int) config('launchpad.internal_linking.index_boost.max_targets', 25));
        $maxSources = max(1, (int) config('launchpad.internal_linking.index_boost.max_sources_per_target', 3));
        $maxPerSource = max(1, (int) config('launchpad.internal_linking.index_boost.max_links_per_source', 3));

        $indexedIds = PageIndexState::withoutGlobalScope(SiteScope::class)
            ->where('site_id', $site->id)
            ->where('index_verdict', 'PASS')
            ->whereNotNull('content_id')
            ->pluck('content_id')->unique();

        $sources = $indexedIds->isEmpty() ? collect() : Content::withoutGlobalScope(SiteScope::class)
            ->where('site_id', $site->id)
            ->where('kind', ContentKind::Page->value)
            ->where('status', ContentStatus::Published->value)
            ->whereNotNull('wp_post_id')
            ->whereIn('id', $indexedIds->all())
            ->get()
            ->reject(fn (Content $c): bool => $c->isPublishProtected());

        $targets = Content::withoutGlobalScope(SiteScope::class)
            ->where('site_id', $site->id)
            ->where('kind', ContentKind::Page->value)
            ->where('status', ContentStatus::Published->value)
            ->whereNotNull('wp_post_id')
            ->whereNotIn('id', $indexedIds->all())
            ->where('published_at', '>=', now()->subDays($window))
            ->orderByDesc('published_at')
            ->limit($maxTargets)
            ->get();

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
            foreach ($this->rankedSources($sources, $target) as $source) {
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
     * Indexed source pages for a target, its own silo first (topical relevance), then the rest — so a new
     * page always earns a few inbound links, preferring the most relevant. Never the target itself.
     *
     * @param  Collection<int, Content>  $sources
     * @return list<Content>
     */
    private function rankedSources(Collection $sources, Content $target): array
    {
        $siloId = $target->matched_silo_id ?? $target->silo_id;

        return $sources
            ->reject(fn (Content $s): bool => (string) $s->id === (string) $target->id)
            ->sortByDesc(fn (Content $s): int => $siloId !== null && $s->silo_id === $siloId ? 1 : 0)
            ->values()
            ->all();
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
