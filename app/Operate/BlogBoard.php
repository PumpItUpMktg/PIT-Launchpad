<?php

namespace App\Operate;

use App\Enums\BlogTargetStatus;
use App\Enums\ContentKind;
use App\Enums\ContentStatus;
use App\Enums\RenderStatus;
use App\Models\BlogTarget;
use App\Models\Content;
use App\Models\Scopes\SiteScope;
use App\Models\Silo;
use App\Models\Site;
use App\Publishing\TenantStorage;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

/**
 * The unified Blog pipeline read model (operate relay): one surface, three stages —
 * Candidates → Review → Published — cross-tenant with site/silo filters.
 *
 * Data path (per the discovery pass): everything is native Launchpad tables. Candidates and
 * drafts are `contents` rows (kind=post) written by the §6a funnel / directed lane; consumption
 * linkage is `blog_targets.article_ref` → the article (BOTH lanes) plus `Content.target_keyword_id`
 * (directed lane only) — so the published grouping joins through blog_targets and never relies on
 * the forward link alone. The "page a keyword provides relevance for" is the silo's pillar
 * (`Silo.pillar_content_id`): queued longtails have no target_content_id by construction.
 */
class BlogBoard
{
    /** Queued-target counts at or below this read as "near-empty" on the dashboard. */
    public const NEAR_EMPTY = 1;

    /**
     * Candidates awaiting triage — directed (queued keyword targets) first, then reactive by score.
     *
     * @return list<array<string, mixed>>
     */
    public function candidates(?string $siteId = null, ?string $siloId = null): array
    {
        $rows = $this->posts($siteId, $siloId)
            ->whereIn('status', [ContentStatus::Candidate->value, ContentStatus::Scored->value])
            ->with(['site', 'matchedSilo.pillarContent', 'targetKeyword'])
            ->get()
            // Promote moves a candidate to the REVIEW tab the moment drafting starts — a
            // promoted item never lingers here looking untouched.
            ->reject(fn (Content $c) => in_array($c->generationState(), ['generating', 'failed'], true));

        return $rows
            ->sortBy([
                fn (Content $a, Content $b) => ($b->target_keyword_id !== null ? 1 : 0) <=> ($a->target_keyword_id !== null ? 1 : 0),
                fn (Content $a, Content $b) => (float) ($b->relevance_score ?? 0) <=> (float) ($a->relevance_score ?? 0),
            ])
            ->values()
            ->map(fn (Content $c) => [
                'id' => (string) $c->id,
                'title' => (string) $c->title,
                'directed' => $c->target_keyword_id !== null,
                'keyword' => $c->targetKeyword?->query,
                'target_page' => $c->matchedSilo?->pillarContent?->title,
                'source' => $c->target_keyword_id !== null ? 'directed' : (string) ($c->source_name ?? 'feed'),
                'silo' => $c->matchedSilo?->name,
                'tenant' => $c->site?->brand_name,
                'angle' => $c->angle_hint,
                'score' => $c->relevance_score !== null ? round((float) $c->relevance_score, 2) : null,
            ])
            ->all();
    }

    /**
     * Drafts awaiting review (+ surfaced failures, flagged-first like the review queue).
     *
     * @return list<array<string, mixed>>
     */
    public function review(?string $siteId = null, ?string $siloId = null): array
    {
        $statuses = [
            ContentStatus::RenderFailed->value,
            ContentStatus::PublishFailed->value,
            ContentStatus::InReview->value,
            ContentStatus::NeedsReview->value,
        ];

        // The review stage also owns the DRAFTING window: a promoted candidate appears here as a
        // "writing" card the moment its job queues, and a failed draft surfaces here with a retry —
        // never stranded invisible on the Candidates tab.
        $rows = $this->posts($siteId, $siloId)
            ->where(fn (Builder $q) => $q
                ->whereIn('status', $statuses)
                ->orWhereIn('status', [ContentStatus::Candidate->value, ContentStatus::Scored->value]))
            ->with(['site', 'matchedSilo', 'targetKeyword', 'renderJobs'])
            ->get()
            ->filter(fn (Content $c) => in_array($c->status->value, $statuses, true)
                || in_array($c->generationState(), ['generating', 'failed'], true));

        $priority = array_flip($statuses); // failures 0–1, in_review 2, needs_review 3

        return $rows
            ->sortBy([
                fn (Content $a, Content $b) => $this->reviewRank($a, $priority) <=> $this->reviewRank($b, $priority),
                fn (Content $a, Content $b) => $a->created_at <=> $b->created_at,
            ])
            ->values()
            ->map(fn (Content $c) => [
                'id' => (string) $c->id,
                'title' => (string) $c->title,
                'status' => $c->status->value,
                'state' => $this->reviewState($c),
                'has_draft' => $c->hasDraft(),
                'draft_error' => $c->draftError(),
                'keyword' => $c->targetKeyword->query ?? $this->consumedKeywordFor($c),
                'silo' => $c->matchedSilo?->name,
                'tenant' => $c->site?->brand_name,
                'excerpt' => Str::words(trim(strip_tags((string) $c->body)), 100, '…'),
                'image' => $this->thumbnail($c),
            ])
            ->all();
    }

    /** Any card still writing? — the tab polls only while something is in motion. */
    public function anyWriting(?string $siteId = null, ?string $siloId = null): bool
    {
        return collect($this->review($siteId, $siloId))->contains(fn (array $c) => $c['state'] === 'writing');
    }

    /**
     * The card's single lifecycle word: writing (job in flight) / draft_failed (retry) /
     * undrafted (borderline candidate routed to review without a draft — offer Generate) /
     * the content status for everything drafted.
     */
    private function reviewState(Content $c): string
    {
        return match (true) {
            $c->isGenerating() => 'writing',
            $c->draftError() !== null => 'draft_failed',
            ! $c->hasDraft() => 'undrafted',
            default => $c->status->value,
        };
    }

    /**
     * @param  array<string, int>  $priority
     */
    private function reviewRank(Content $c, array $priority): int
    {
        return match ($this->reviewState($c)) {
            'draft_failed' => 0,             // broken first
            'writing' => 2,                  // in motion, right behind push failures
            'undrafted' => 4,
            default => 3 + (int) ($priority[$c->status->value] ?? 5),
        };
    }

    /** The generate-time rendered image (fal → R2), if one exists — the card's thumbnail. */
    private function thumbnail(Content $c): ?string
    {
        $job = $c->renderJobs->first(fn ($j) => $j->status === RenderStatus::Succeeded && $j->r2_key !== null);
        if ($job === null) {
            return null;
        }

        try {
            return Storage::disk(TenantStorage::DISK)->url((string) $job->r2_key);
        } catch (\Throwable) {
            return null;
        }
    }

    /**
     * Published, grouped by CONSUMED KEYWORD → the page it provides relevance for (the silo's
     * pillar). Unconsumed queued targets appear as bare groups (count 0) so gaps surface first;
     * reactive articles with no consumed keyword bucket per-silo under "Freshness". Groups sort
     * by article count ascending.
     *
     * @return list<array<string, mixed>>
     */
    public function published(?string $siteId = null, ?string $siloId = null): array
    {
        $published = $this->posts($siteId, $siloId)
            ->where('status', ContentStatus::Published->value)
            ->with(['site', 'matchedSilo.pillarContent', 'targetKeyword'])
            ->get();

        // Every non-dismissed target is a group — consumed or bare (the relevance map).
        $targets = BlogTarget::withoutGlobalScope(SiteScope::class)
            ->when($siteId !== null, fn (Builder $q) => $q->where('site_id', $siteId))
            ->when($siloId !== null, fn (Builder $q) => $q->where('silo_id', $siloId))
            ->where('status', '!=', BlogTargetStatus::Dismissed->value)
            ->with(['keyword', 'silo.pillarContent', 'site'])
            ->get();

        $articlesByRef = $published->keyBy(fn (Content $c) => (string) $c->id);
        $groupedArticleIds = [];

        $groups = [];
        foreach ($targets as $target) {
            $articles = [];
            if ($target->article_ref !== null && $articlesByRef->has((string) $target->article_ref)) {
                $articles[] = $this->articleCard($articlesByRef->get((string) $target->article_ref));
                $groupedArticleIds[(string) $target->article_ref] = true;
            }

            $groups[] = [
                'kind' => 'keyword',
                'keyword' => $target->keyword->query ?? '—',
                'target_page' => $target->silo?->pillarContent?->title,
                'target_url' => $this->url($target->site, $target->silo?->pillarContent?->slug),
                'silo' => $target->silo?->name,
                'tenant' => $target->site?->brand_name,
                'status' => $target->status->value,
                'articles' => $articles,
            ];
        }

        // Directed posts whose target row is gone still group by their forward keyword link.
        $remaining = $published->reject(fn (Content $c) => isset($groupedArticleIds[(string) $c->id]));
        foreach ($remaining->filter(fn (Content $c) => $c->target_keyword_id !== null) as $post) {
            $groups[] = [
                'kind' => 'keyword',
                'keyword' => $post->targetKeyword->query ?? '—',
                'target_page' => $post->matchedSilo?->pillarContent?->title,
                'target_url' => $this->url($post->site, $post->matchedSilo?->pillarContent?->slug),
                'silo' => $post->matchedSilo?->name,
                'tenant' => $post->site?->brand_name,
                'status' => 'published',
                'articles' => [$this->articleCard($post)],
            ];
        }

        // Reactive articles (no consumed keyword): per-silo Freshness buckets → the silo's pillar.
        $reactive = $remaining->filter(fn (Content $c) => $c->target_keyword_id === null);
        foreach ($reactive->groupBy(fn (Content $c) => (string) ($c->matched_silo_id ?? '')) as $bucket) {
            /** @var Content $first */
            $first = $bucket->first();
            $groups[] = [
                'kind' => 'freshness',
                'keyword' => null,
                'target_page' => $first->matchedSilo?->pillarContent?->title,
                'target_url' => $this->url($first->site, $first->matchedSilo?->pillarContent?->slug),
                'silo' => $first->matchedSilo->name ?? 'No silo',
                'tenant' => $first->site?->brand_name,
                'status' => 'freshness',
                'articles' => $bucket->sortByDesc('published_at')->values()->map(fn (Content $c) => $this->articleCard($c))->all(),
            ];
        }

        // Bare targets first — the gaps are the point of this tab.
        usort($groups, fn (array $a, array $b) => count($a['articles']) <=> count($b['articles']));

        return $groups;
    }

    /**
     * The blog-targets drawer: per silo, the ordered unconsumed queue (volume-led, then FIFO —
     * mirrors the consumption order).
     *
     * @return list<array<string, mixed>>
     */
    public function targets(?string $siteId = null, ?string $siloId = null): array
    {
        return BlogTarget::withoutGlobalScope(SiteScope::class)
            ->when($siteId !== null, fn (Builder $q) => $q->where('site_id', $siteId))
            ->when($siloId !== null, fn (Builder $q) => $q->where('silo_id', $siloId))
            ->where('status', BlogTargetStatus::Queued->value)
            ->with(['keyword', 'silo', 'site'])
            ->get()
            ->sortBy([
                fn (BlogTarget $a, BlogTarget $b) => strcasecmp((string) $a->silo?->name, (string) $b->silo?->name),
                fn (BlogTarget $a, BlogTarget $b) => (int) ($b->keyword->volume ?? 0) <=> (int) ($a->keyword->volume ?? 0),
                fn (BlogTarget $a, BlogTarget $b) => $a->queued_at <=> $b->queued_at,
            ])
            ->values()
            ->map(fn (BlogTarget $t) => [
                'id' => (string) $t->id,
                'keyword' => $t->keyword?->query,
                'volume' => $t->keyword?->volume,
                'silo' => $t->silo?->name,
                'tenant' => $t->site?->brand_name,
                'queued_at' => $t->queued_at->toDateString(),
            ])
            ->all();
    }

    /** Silo filter options for the current site scope. */
    public function siloOptions(?string $siteId = null): array
    {
        return Silo::withoutGlobalScope(SiteScope::class)
            ->when($siteId !== null, fn (Builder $q) => $q->where('site_id', $siteId))
            ->orderBy('name')
            ->pluck('name', 'id')
            ->all();
    }

    /**
     * @return array<string, mixed>
     */
    private function articleCard(Content $c): array
    {
        return [
            'id' => (string) $c->id,
            'title' => (string) $c->title,
            'published_at' => $c->published_at?->toDateString(),
            'url' => $this->url($c->site, $c->slug),
        ];
    }

    private function url(?Site $site, ?string $slug): ?string
    {
        if ($site === null || $slug === null || trim((string) $site->domain_url) === '') {
            return null;
        }

        return rtrim((string) $site->domain_url, '/').'/'.ltrim($slug, '/');
    }

    /**
     * @return Builder<Content>
     */
    private function posts(?string $siteId, ?string $siloId): Builder
    {
        return Content::withoutGlobalScope(SiteScope::class)
            ->where('kind', ContentKind::Post->value)
            ->when($siteId !== null, fn (Builder $q) => $q->where('site_id', $siteId))
            ->when($siloId !== null, fn (Builder $q) => $q->where('matched_silo_id', $siloId));
    }

    /** The reverse consumption link (reactive lane): blog_targets.article_ref → this post. */
    private function consumedKeywordFor(Content $c): ?string
    {
        return BlogTarget::withoutGlobalScope(SiteScope::class)
            ->where('article_ref', (string) $c->id)
            ->with('keyword')
            ->first()?->keyword?->query;
    }
}
