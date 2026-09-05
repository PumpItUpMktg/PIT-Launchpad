<?php

namespace App\Publishing\Links;

use App\Enums\LinkPlanItemStatus;
use App\Enums\LinkPlanStatus;
use App\Integrations\IndexNow\IndexNowSubmitter;
use App\Jobs\PublishContent;
use App\Models\Content;
use App\Models\LinkPlan;
use App\Models\Scopes\SiteScope;
use App\Support\PublicUrl;

/**
 * Applies an approved {@see LinkPlan}: writes each approved link into its source page ({@see LinkInjector} —
 * wrap the anchor term, or an appended "Related:" link, or a whole-page republish for the spine sources),
 * re-publishes each distinct touched source ({@see PublishContent}, idempotent by ULID), then — only for the
 * town pages that now carry ≥1 inbound link (the no-orphan guard, {@see InternalLinkGraph}) — submits their
 * URLs to IndexNow. Nothing here runs without an operator having approved: this is the commit step.
 */
class LinkPlanCommitter
{
    public function __construct(
        private readonly LinkInjector $injector,
        private readonly InternalLinkGraph $graph,
        private readonly IndexNowSubmitter $indexNow,
    ) {}

    /**
     * @return array{applied: int, republished: int, submitted: list<string>, orphaned: list<string>}
     */
    public function apply(LinkPlan $plan, ?string $actorId = null): array
    {
        $site = $plan->site;
        $items = $plan->items()->where('status', LinkPlanItemStatus::Approved->value)->get();

        $repush = [];   // distinct source content ids to re-publish
        $applied = 0;
        foreach ($items as $item) {
            $source = $item->source_content_id !== null ? $this->content($item->source_content_id) : null;
            $target = $this->content($item->target_content_id);
            if ($target === null) {
                continue;
            }

            if ($source !== null) {
                $path = '/'.ltrim((string) $target->slug, '/');
                // Spine sources (Market/Areas) carry a null anchor — the page regenerates its town spine on
                // republish, so no injection; the anchor sources wrap the town name (else an appended link).
                if ($item->anchor_term !== null) {
                    $this->injector->inject($source, $item->anchor_term, $path)
                        || $this->injector->appendRelated($source, $item->anchor_term, $path);
                }
                if ($source->wp_post_id !== null) {
                    $repush[(string) $source->id] = true;
                }
            }

            $item->forceFill(['status' => LinkPlanItemStatus::Applied, 'applied_at' => now()])->save();
            $applied++;
        }

        foreach (array_keys($repush) as $sourceId) {
            PublishContent::dispatch($sourceId, $actorId);
        }

        // No-orphan guard: rebuild the graph (reads the just-saved stored content) and submit to IndexNow
        // ONLY the target towns that now have an inbound link — never a zero-inbound page.
        $graph = $this->graph->build($site);
        $submitted = [];
        $orphaned = [];
        foreach ($items->pluck('target_content_id')->unique() as $targetId) {
            $target = $this->content((string) $targetId);
            if ($target === null) {
                continue;
            }
            if ($graph->inbound((string) $targetId) === []) {
                $orphaned[] = (string) $targetId;

                continue;
            }
            // Publish-hold (location-integrity): never announce a held location's page to IndexNow — its
            // HTML hasn't shipped, so the URL would 404. Deferring discovery is the point of the hold.
            if ($target->isPublishHeld()) {
                continue;
            }
            $url = PublicUrl::forContent($site->domain_url, $target); // canonical (home → root, never /home/)
            if ($url !== null) {
                $submitted[] = $url;
            }
        }

        if ($submitted !== []) {
            $this->indexNow->submit($site, $submitted);
        }

        $plan->forceFill(['status' => LinkPlanStatus::Applied])->save();

        return ['applied' => $applied, 'republished' => count($repush), 'submitted' => $submitted, 'orphaned' => $orphaned];
    }

    private function content(string $id): ?Content
    {
        return Content::withoutGlobalScope(SiteScope::class)->find($id);
    }
}
