<?php

namespace App\Jobs;

use App\ContentEngine\Drafting\DraftFailure;
use App\ContentEngine\Questions\QuestionRefresher;
use App\Models\Content;
use App\Models\Scopes\SiteScope;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Throwable;

/**
 * Run the questions lane's Refresh off the web request (models GeneratePost): the board action
 * dispatches this and returns; the worker makes the one model call and rewrites the two body blocks.
 * The post carries the "generating" marker from ::enqueue until the refresh lands (→ needs_review) or
 * fails (→ draft_error on the still-published row, shown on the Refresh panel). tries=1: the call is
 * not cheap and a transport failure won't fix itself — the operator re-triggers.
 */
class RefreshPostForQuestion implements ShouldQueue
{
    use Queueable;

    public int $tries = 1;

    public int $timeout = 600;

    public function __construct(
        public readonly string $contentId,
        public readonly ?string $actorId = null,
    ) {}

    /** Stamp the row "generating" and enqueue (named enqueue, not queue — see GeneratePost). */
    public static function enqueue(Content $post, ?string $actorId = null): void
    {
        $post->markGenerating();
        self::dispatch($post->id, $actorId);
    }

    public function handle(QuestionRefresher $refresher): void
    {
        $post = Content::withoutGlobalScope(SiteScope::class)->find($this->contentId);
        if ($post === null) {
            return;
        }

        try {
            $refresher->refresh($post);
        } catch (Throwable $e) {
            // Record on the row (clears "generating") and let the job succeed — the panel shows the cause.
            $post->recordDraftFailure(DraftFailure::fromException($e));
        }
    }

    /** A dead job (timeout / re-reservation) must not leave the row stuck "Refreshing…". */
    public function failed(?Throwable $exception): void
    {
        Content::withoutGlobalScope(SiteScope::class)
            ->find($this->contentId)
            ?->recordDraftFailure(DraftFailure::fromException($exception ?? new \RuntimeException('Refresh job failed.')));
    }
}
