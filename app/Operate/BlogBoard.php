<?php

namespace App\Operate;

use App\Enums\BlogTargetStatus;
use App\Enums\CandidateClassification;
use App\Enums\ContentKind;
use App\Enums\ContentStatus;
use App\Enums\RenderStatus;
use App\Integrations\UrlInspection\IndexInspector;
use App\Integrations\UrlInspection\NullIndexInspector;
use App\Models\BlogTarget;
use App\Models\Content;
use App\Models\Scopes\SiteScope;
use App\Models\Silo;
use App\Models\Site;
use App\Publishing\Redirects\LegacyContentReviver;
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

    /** An approved post older than this (seconds) with no rendering progress = a stalled publish job. */
    public const STALLED_AFTER_SECONDS = 300;

    public function __construct(private readonly IndexInspector $inspector = new NullIndexInspector) {}

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
                // Legacy-revival candidates (a recovered top-performer) sort ahead of feed candidates,
                // most lost impressions first — the biggest reclaim jumps to the top of the backlog.
                fn (Content $a, Content $b) => (int) $this->revivedImpressions($b) <=> (int) $this->revivedImpressions($a),
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
                // §6a two-axis classification (PR 4): scope drives the silo→local grouping on the board.
                'scope' => $c->meta['scope'] ?? null,
                // A hand-typed operator idea (PR 7) — shown cap-exempt in the group (never hidden by the cap).
                'manual' => $c->source_name === 'manual',
                // The article's real publish date (source pubDate) when captured at ingest, else the ingest date.
                'date' => $c->meta['source_published_at'] ?? $c->created_at?->toDateString(),
                'classification' => $this->classificationOf($c)?->label(),
                'classification_kind' => $this->classificationOf($c)?->pillKind(),
                'tenant' => $c->site?->brand_name,
                'angle' => $c->angle_hint,
                'excerpt' => $this->excerpt($c, $c->angle_hint),
                'score' => $c->relevance_score !== null ? round((float) $c->relevance_score, 2) : null,
                'revived' => $this->revivedUrls($c) !== [],
                'revived_impressions' => $this->revivedImpressions($c),
                'revived_urls' => count($this->revivedUrls($c)),
            ])
            ->all();
    }

    /**
     * Group flat candidate rows (from {@see candidates()}) by silo, then LOCAL-FIRST within each silo, and
     * cap the visible rows per group — so a firehose silo shows its top few with a "+N more" tail instead of
     * a wall of cards. Pure over the row arrays (the page filters by score first, then groups). Groups are
     * ordered biggest-backlog-first. A row's incoming score/priority order is preserved within each scope
     * (a stable sort on the local-first key). `$cap` of 0 shows every row.
     *
     * @param  list<array<string, mixed>>  $rows
     * @return list<array{silo: string, total: int, local: int, visible: list<array<string, mixed>>, overflow: int}>
     */
    public function group(array $rows, int $cap = 8): array
    {
        return collect($rows)
            ->groupBy(fn (array $r): string => is_string($r['silo'] ?? null) && $r['silo'] !== '' ? $r['silo'] : '— No silo —')
            ->map(function ($group, string $silo) use ($cap): array {
                // Stable sort (PHP 8 sort is stable): local before general/unknown, score order kept within each.
                $ordered = $group->sortBy(fn (array $r): int => ($r['scope'] ?? null) === 'local' ? 0 : 1)->values();

                // Manual (hand-typed) candidates are CAP-EXEMPT — an operator's explicit ask is never hidden
                // behind a silo's ingestion backlog. Show all of them, then fill the rest up to the cap.
                $manual = $ordered->filter(fn (array $r): bool => (bool) ($r['manual'] ?? false))->values();
                $rest = $ordered->reject(fn (array $r): bool => (bool) ($r['manual'] ?? false))->values();
                $visibleRest = $cap > 0 ? $rest->take($cap) : $rest;

                return [
                    'silo' => $silo,
                    'total' => $ordered->count(),
                    'local' => $ordered->where('scope', 'local')->count(),
                    'visible' => $manual->concat($visibleRest)->all(),
                    'overflow' => max(0, $rest->count() - $visibleRest->count()),
                ];
            })
            ->sortByDesc('total')
            ->values()
            ->all();
    }

    /** The §6a timeliness classification stashed on the candidate's meta (drives the board pill). */
    private function classificationOf(Content $c): ?CandidateClassification
    {
        $value = $c->meta['classification'] ?? null;

        return is_string($value) ? CandidateClassification::tryFrom($value) : null;
    }

    /**
     * Posts in flight to WordPress right now — approved (queued), rendering, or pushing. Approve
     * dispatches a background job, so an approved post briefly leaves Review before it lands in
     * Published; this is the "it's working, hang on" indicator that fills that gap.
     *
     * @return list<array<string, mixed>>
     */
    public function publishing(?string $siteId = null, ?string $siloId = null): array
    {
        return $this->posts($siteId, $siloId)
            ->whereIn('status', [
                ContentStatus::Approved->value,
                ContentStatus::Rendering->value,
                ContentStatus::Publishing->value,
            ])
            ->with(['site', 'matchedSilo', 'targetKeyword'])
            ->latest('updated_at')
            ->get()
            // The Publish queue is the RELEASED half of the two-gate split: an approved post that an
            // operator has explicitly sent over from the Approved (preview) page, plus anything already
            // in flight (rendering/publishing). Un-released approved posts wait on the Approved page.
            ->filter(fn (Content $c) => $c->status !== ContentStatus::Approved || $c->isReleasedToPublish())
            ->map(fn (Content $c) => $this->publishCard($c))
            ->values()
            ->all();
    }

    /**
     * The operator's Approved (preview / QA) queue: approved posts NOT yet released to Publish. This
     * is the "page in the middle" — an operator previews the fully-rendered post here (image + body +
     * links + SEO) and clicks Send to Publish to move it onto the push-only Publish page.
     *
     * @return list<array<string, mixed>>
     */
    public function approved(?string $siteId = null, ?string $siloId = null): array
    {
        return $this->posts($siteId, $siloId)
            ->where('status', ContentStatus::Approved->value)
            ->with(['site', 'matchedSilo', 'targetKeyword'])
            ->latest('updated_at')
            ->get()
            ->reject(fn (Content $c) => $c->isReleasedToPublish())
            ->map(fn (Content $c) => $this->publishCard($c))
            ->values()
            ->all();
    }

    /**
     * The shared publish/approved card shape (both stages render the same rich card).
     *
     * @return array<string, mixed>
     */
    private function publishCard(Content $c): array
    {
        return [
            'id' => (string) $c->id,
            'title' => (string) $c->title,
            'tenant' => $c->site?->brand_name,
            'silo' => $c->matchedSilo?->name,
            'source' => $c->target_keyword_id !== null ? 'directed' : (string) ($c->source_name ?? 'feed'),
            'date' => $c->created_at?->toDateString(),
            'keyword' => $c->targetKeyword?->query,
            'excerpt' => $this->excerpt($c),
            'score' => $c->relevance_score !== null ? round((float) $c->relevance_score, 2) : null,
            'state' => match ($c->status) {
                ContentStatus::Rendering => 'rendering image',
                ContentStatus::Publishing => 'pushing to WordPress',
                default => 'queued to publish',
            },
            // "Stuck": approved (job dispatched, never started rendering) for longer than a job
            // should ever sit unprocessed. Flags a stalled worker and offers the inline escape hatch.
            'stalled' => $c->status === ContentStatus::Approved
                && $c->updated_at !== null
                && $c->updated_at->lt(now()->subSeconds(self::STALLED_AFTER_SECONDS)),
        ];
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
                'source' => $c->target_keyword_id !== null ? 'directed' : (string) ($c->source_name ?? 'feed'),
                'date' => $c->created_at?->toDateString(),
                'tenant' => $c->site?->brand_name,
                'excerpt' => $this->excerpt($c, $c->angle_hint),
                'score' => $c->relevance_score !== null ? round((float) $c->relevance_score, 2) : null,
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
        // The in-flight marker is checked directly (not via generationState(), which reports
        // 'drafted' whenever a draft exists) so a REGENERATING drafted post reads as writing too.
        // markGenerating stamps it; failure clears it; a successful draft rebuilds meta wholesale.
        return match (true) {
            ($c->meta['generating_at'] ?? null) !== null => 'writing',
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

    /** A short card excerpt from the post body, falling back to a provided hint (candidate angle). */
    private function excerpt(Content $c, ?string $fallback = null): ?string
    {
        $body = trim(strip_tags((string) $c->body));
        if ($body !== '') {
            return Str::words($body, 30, '…');
        }

        return $fallback !== null && trim($fallback) !== '' ? trim($fallback) : null;
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
        $url = $this->url($c->site, $c->slug);
        // Authoritative index coverage (cache-only — no live API call here; populated by launchpad:audit-index).
        $status = ($c->site !== null && $url !== null) ? $this->inspector->cached($c->site, $url) : null;

        return [
            'id' => (string) $c->id,
            'title' => (string) $c->title,
            'published_at' => $c->published_at?->toDateString(),
            'url' => $url,
            'index' => $status !== null
                ? ['label' => $status->state->label(), 'indexed' => $status->indexed(), 'state' => $status->state->value]
                : null,
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

    /**
     * The old-site URLs a legacy-revival candidate reclaims ({@see LegacyContentReviver}) —
     * empty for an ordinary candidate. Honors both the list and the legacy single-URL shape.
     *
     * @return list<string>
     */
    private function revivedUrls(Content $c): array
    {
        $meta = $c->meta ?? [];
        $urls = array_values(array_filter((array) ($meta['revived_from_urls'] ?? []), 'is_string'));
        if ($urls === [] && is_string($meta['revived_from_url'] ?? null)) {
            $urls[] = $meta['revived_from_url'];
        }

        return $urls;
    }

    private function revivedImpressions(Content $c): int
    {
        return (int) (($c->meta ?? [])['revived_impressions'] ?? 0);
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
