<?php

namespace App\Jobs;

use App\ContentEngine\Drafting\DraftFailedException;
use App\ContentEngine\Drafting\DraftFailure;
use App\ContentEngine\Generation\PostGenerator;
use App\Models\Content;
use App\Models\Scopes\SiteScope;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Throwable;

/**
 * Run the on-demand "Generate post" off the web request: the Filament action
 * dispatches this and returns immediately, and the worker executes the expensive
 * Sonnet draft + fal render with no FPM clock ticking. Models PublishContent.
 *
 * The row carries a "generating" marker (stamped at dispatch by ::enqueue) so the
 * surfaces show that state until the draft lands (→ needs_review) or fails
 * (→ draft_error). A failed draft is recorded by the engine, not retried — the
 * call is expensive and a budget/transport failure won't fix itself; the operator
 * re-triggers from the row. tries=1 for that reason.
 *
 * $timeout must stay BELOW the queue connection's retry_after (config/queue.php),
 * or a long Sonnet+fal run gets re-reserved mid-flight and burns its one attempt
 * (the MaxAttemptsExceeded the database default 90s caused). failed() records the
 * failure so a dead job reads "Draft failed", never "Generating" forever.
 */
class GeneratePost implements ShouldQueue
{
    use Queueable;

    public int $tries = 1;

    public int $timeout = 600;

    public function __construct(
        public readonly string $contentId,
        public readonly ?string $marketId = null,
        public readonly ?string $actorId = null,
    ) {}

    /**
     * Stamp the row "generating" and enqueue. Marking before dispatch means the
     * surfaces show the generating state immediately, before the worker picks up.
     *
     * Named `enqueue`, NOT `queue`: Laravel's Bus dispatcher treats a `queue`
     * method on a job as its custom-queueing hook (`$command->queue($queue, …)`),
     * which would shadow this helper and pass the queue connection as $content.
     */
    public static function enqueue(Content $content, ?string $marketId = null, ?string $actorId = null): void
    {
        $content->markGenerating();

        self::dispatch($content->id, $marketId, $actorId);
    }

    public function handle(PostGenerator $generator): void
    {
        $content = Content::withoutGlobalScope(SiteScope::class)->find($this->contentId);

        if ($content === null) {
            return;
        }

        try {
            $generator->generate($content, $this->marketId);
        } catch (DraftFailedException) {
            // Expected: the engine already recorded the failure (marker + cleared
            // generating) and logged. Swallow so the job succeeds — the row shows
            // "Draft failed" and we don't burn the attempt as a job failure.
        }
    }

    /**
     * A dead job (timeout/re-reservation, or an unexpected throw) must not leave
     * the row stuck "Generating". Record the failure so it reads "Draft failed".
     */
    public function failed(?Throwable $exception): void
    {
        Content::withoutGlobalScope(SiteScope::class)
            ->find($this->contentId)
            ?->recordDraftFailure(DraftFailure::fromException($exception ?? new \RuntimeException('Generation job failed.')));
    }
}
