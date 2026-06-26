<?php

namespace App\Pages;

use App\ContentEngine\Drafting\GroundingReadiness;
use App\Enums\ContentStatus;
use App\Models\Content;
use App\Standard\StandardPageIntake;
use Carbon\CarbonInterface;
use Illuminate\Support\Carbon;

/**
 * The single place a page's lifecycle is read into the canonical {@see PageState} and rendered for an
 * {@see Audience}. Every surface goes through here — no state strings scattered per-component — so the
 * operator screen now and the client screen later cannot drift.
 *
 * Resolution order mirrors the engine's own gates: in-flight → drafted-state → (no draft) the real
 * blocker (composer / grounding) → a retryable failure → ready. The operator tail is built from the
 * row's own fields; it is diagnostic only and never reaches the client.
 */
class PageStatePresenter
{
    public function __construct(private readonly GroundingReadiness $grounding = new GroundingReadiness) {}

    public function present(Content $content, Audience $audience): PagePresentation
    {
        $state = $this->resolve($content);

        return new PagePresentation(
            state: $state,
            clientLine: $state->clientLine(),
            whoseMove: $state->whoseMove($audience),
            tone: $state->tone(),
            operatorTail: $audience === Audience::Operator ? $this->tail($content, $state) : null,
        );
    }

    /** Read a page row into its canonical state. */
    public function resolve(Content $content): PageState
    {
        if ($content->isGenerating()) {
            return PageState::Writing;
        }

        if ($content->hasDraft()) {
            return match ($content->status) {
                ContentStatus::Published => PageState::Live,
                ContentStatus::Approved => PageState::Approved,
                ContentStatus::Rendering, ContentStatus::Publishing => PageState::Publishing,
                ContentStatus::RenderFailed, ContentStatus::PublishFailed => PageState::Failed,
                default => PageState::ReadyToReview,
            };
        }

        // No draft yet — the real blocker wins over a stale failure, which wins over "ready".
        if ($content->wireframe_kit_id === null) {
            return PageState::HeldComposer;
        }

        // Missing required brand-narrative intake is its own hold, distinct from missing entities —
        // checked before the grounding gate so the operator sees "needs intake", not a vague pending.
        if (StandardPageIntake::missingRequired($content) !== []) {
            return PageState::HeldIntake;
        }

        if (! $this->grounding->ready($content)) {
            return PageState::HeldGrounding;
        }

        if ($content->draftError() !== null) {
            return PageState::Failed;
        }

        return PageState::ReadyToGenerate;
    }

    /** The append-only operator diagnostic, after the sacred line. Null when there's nothing useful. */
    private function tail(Content $content, PageState $state): ?string
    {
        return match ($state) {
            PageState::Writing => $this->queuedTail($content),
            PageState::ReadyToReview => $this->relative('drafted', $content->updated_at),
            PageState::Approved => $this->relative('approved', $content->updated_at),
            PageState::Publishing => 'publishing — pushing to WordPress',
            PageState::Live => $this->liveTail($content),
            PageState::HeldComposer => 'composer pending',
            PageState::HeldGrounding => 'grounding pending — Territory→§1 Market',
            PageState::HeldIntake => $this->intakeTail($content),
            PageState::Failed => $this->failedTail($content),
            PageState::ReadyToGenerate => null,
        };
    }

    private function queuedTail(Content $content): string
    {
        $at = $content->meta['generating_at'] ?? null;

        return is_string($at) ? 'queued '.Carbon::parse($at)->diffForHumans() : 'queued';
    }

    private function liveTail(Content $content): string
    {
        $parts = [];
        if ($content->wp_post_id !== null) {
            $parts[] = 'post #'.$content->wp_post_id;
        }
        $slug = (string) $content->slug;
        if ($slug !== '') {
            $parts[] = '/'.ltrim($slug, '/');
        }
        if ($content->published_at !== null) {
            $parts[] = 'published '.$content->published_at->diffForHumans();
        }

        return $parts === [] ? 'live' : implode(' · ', $parts);
    }

    private function intakeTail(Content $content): string
    {
        $missing = StandardPageIntake::missingRequired($content);

        return $missing === [] ? 'needs intake' : 'needs intake — '.implode(', ', $missing);
    }

    private function failedTail(Content $content): string
    {
        // Generate failure records draft_error; a publish failure records last_publish_error. Coalesce
        // to '' so a render-failure with no publish error string is still null-safe at runtime.
        $error = trim((string) ($content->draftError() ?? $content->last_publish_error));

        return $error !== '' ? $error : 'generation or publish failed';
    }

    private function relative(string $verb, ?CarbonInterface $at): ?string
    {
        return $at !== null ? $verb.' '.$at->diffForHumans() : null;
    }
}
