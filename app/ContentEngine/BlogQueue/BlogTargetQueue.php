<?php

namespace App\ContentEngine\BlogQueue;

use App\Build\GuidedEntityProjector;
use App\Build\TargetKeywordResolver;
use App\Enums\BlogTargetStatus;
use App\Enums\SpokeGranularity;
use App\Enums\SpokeStatus;
use App\Models\BlogTarget;
use App\Models\Content;
use App\Models\Keyword;
use App\Models\Scopes\SiteScope;
use App\Models\Site;
use App\Models\Spoke;

/**
 * The per-silo BLOG TARGET QUEUE (longtail relay): unconsumed informational keywords, ordered
 * coverage-gap-first then volume desc — the directed assignment lane the news-post drafting pulls
 * from. One keyword, one home: sync() reconciles the queue against the confirmed blueprint at
 * materialize time (a spoke flipped blog_target→fold drops its QUEUED row; drafted/published rows
 * are history and stay), and a keyword already targeted by a page never enqueues.
 */
class BlogTargetQueue
{
    public function __construct(
        private readonly TargetKeywordResolver $keywords,
        private readonly GuidedEntityProjector $projector,
    ) {}

    /**
     * Reconcile the queue with the confirmed blueprint — called from materialize (silos exist
     * there; the same commit that builds pages). Idempotent:
     *  - every OFFERED blog_target spoke's keyword is enqueued once (unique keyword_id);
     *  - a keyword that already targets a page (target_content_id) is NEVER enqueued (one home);
     *  - a QUEUED row whose spoke is no longer blog_target is removed (the flip moved it, never
     *    duplicated it); consumed rows (drafted/published/dismissed) are history and stay.
     *
     * @return array{enqueued: int, removed: int}
     */
    public function sync(Site $site): array
    {
        $spokes = Spoke::withoutGlobalScope(SiteScope::class)
            ->where('site_id', $site->id)
            ->where('granularity', SpokeGranularity::BlogTarget->value)
            ->where('status', SpokeStatus::Offered->value)
            ->get();

        $enqueued = 0;
        $keptKeywordIds = [];
        foreach ($spokes as $spoke) {
            $silo = $this->projector->siloForSpoke($spoke->id, $site);
            $keyword = $this->keywords->forSpoke($site, $spoke->id, $silo?->id);
            if ($silo === null || $keyword === null) {
                continue; // no silo or no keyword → nothing honest to queue
            }
            if ($keyword->target_content_id !== null) {
                continue; // already a page's target — one keyword, one home
            }

            $keptKeywordIds[] = (string) $keyword->id;
            $target = BlogTarget::withoutGlobalScope(SiteScope::class)
                ->where('keyword_id', $keyword->id)
                ->first();
            if ($target === null) {
                BlogTarget::create([
                    'site_id' => $site->id,
                    'silo_id' => $silo->id,
                    'keyword_id' => $keyword->id,
                    'status' => BlogTargetStatus::Queued,
                    'queued_at' => now(),
                ]);
                $enqueued++;
            }
        }

        // Rows whose spoke flipped away from blog_target: only the un-consumed leave the queue.
        $removed = BlogTarget::withoutGlobalScope(SiteScope::class)
            ->where('site_id', $site->id)
            ->where('status', BlogTargetStatus::Queued->value)
            ->whereNotIn('keyword_id', $keptKeywordIds === [] ? [''] : $keptKeywordIds)
            ->delete();

        return ['enqueued' => $enqueued, 'removed' => (int) $removed];
    }

    /**
     * The top queued target for a site (optionally one silo): coverage-gap-first, then volume
     * desc, then FIFO. Coverage state is a SEPARATE component (main spec) — until it lands, every
     * queued row ranks as a gap, so the order is volume-led. The seam is {@see coverageRank}.
     */
    public function top(Site $site, ?string $siloId = null): ?BlogTarget
    {
        $targets = BlogTarget::withoutGlobalScope(SiteScope::class)
            ->where('site_id', $site->id)
            ->where('status', BlogTargetStatus::Queued->value)
            ->when($siloId !== null, fn ($q) => $q->where('silo_id', $siloId))
            ->with('keyword')
            ->get();

        return $targets
            ->sortBy([
                fn (BlogTarget $a, BlogTarget $b) => $this->coverageRank($a) <=> $this->coverageRank($b),
                fn (BlogTarget $a, BlogTarget $b) => (int) ($b->keyword->volume ?? 0) <=> (int) ($a->keyword->volume ?? 0),
                fn (BlogTarget $a, BlogTarget $b) => $a->queued_at <=> $b->queued_at,
            ])
            ->first();
    }

    /** An article was drafted against this target — consume it (exclusive; never re-assigned). */
    public function markDrafted(BlogTarget $target, Content $article): void
    {
        $target->forceFill([
            'status' => BlogTargetStatus::Drafted,
            'article_ref' => $article->id,
        ])->save();
    }

    /** The consuming article went live. */
    public function markPublished(BlogTarget $target): void
    {
        $target->forceFill(['status' => BlogTargetStatus::Published])->save();
    }

    /** Operator opt-out — the keyword leaves the lane without an article. */
    public function dismiss(BlogTarget $target): void
    {
        $target->forceFill(['status' => BlogTargetStatus::Dismissed])->save();
    }

    /**
     * REACTIVE consumption: a news-driven article assigned to a silo may substantially cover a
     * queued target — consume it so the queue never double-assigns. "Substantially covers" (v1) =
     * the queued keyword's normalized query appears in the article's title or body. Only the
     * article's OWN silo's queue is checked (the queue is silo metadata).
     *
     * @return int how many targets this article consumed
     */
    public function consumeIfCovered(Content $article): int
    {
        $siloId = $article->matched_silo_id ?? $article->silo_id;
        if ($siloId === null) {
            return 0;
        }

        $haystack = mb_strtolower(trim((string) $article->title.' '.strip_tags((string) $article->body)));
        if ($haystack === '') {
            return 0;
        }

        $consumed = 0;
        $targets = BlogTarget::withoutGlobalScope(SiteScope::class)
            ->where('site_id', $article->site_id)
            ->where('silo_id', $siloId)
            ->where('status', BlogTargetStatus::Queued->value)
            ->with('keyword')
            ->get();

        foreach ($targets as $target) {
            $query = mb_strtolower(trim((string) $target->keyword?->query));
            if ($query !== '' && str_contains($haystack, $query)) {
                $this->markDrafted($target, $article);
                $consumed++;
            }
        }

        return $consumed;
    }

    /** Flip drafted→published for every target this article consumed (the publish hook). */
    public function markPublishedByArticle(Content $article): int
    {
        return BlogTarget::withoutGlobalScope(SiteScope::class)
            ->where('site_id', $article->site_id)
            ->where('article_ref', $article->id)
            ->where('status', BlogTargetStatus::Drafted->value)
            ->update(['status' => BlogTargetStatus::Published->value]);
    }

    /**
     * Coverage-state rank for the queue order — gap outranks thin outranks covered. The coverage
     * tracker is a separate component of the main spec; until a state exists on the record every
     * target ranks as a gap (0), so the order is volume-led. Lower = earlier.
     */
    private function coverageRank(BlogTarget $target): int
    {
        return 0;
    }
}
