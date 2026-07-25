<?php

namespace App\Jobs;

use App\ContentEngine\Drafting\DraftFailedException;
use App\ContentEngine\Drafting\DraftFailure;
use App\ContentEngine\Generation\PageGenerator;
use App\ContentEngine\Review\ReviewActions;
use App\Models\Content;
use App\Models\Scopes\SiteScope;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Throwable;

/**
 * Run "Generate page" off the web request: the Filament action dispatches this
 * and returns immediately; the worker runs the expensive Sonnet draft + fal
 * render with no FPM clock. The page-kind analog of GeneratePost — same
 * generating-marker + tries=1 (a failed draft records its cause; the operator
 * re-triggers rather than auto-retrying an expensive call).
 *
 * $timeout must stay BELOW the queue connection's retry_after (config/queue.php)
 * or a long run gets re-reserved mid-flight and burns its one attempt. failed()
 * records the failure so a dead job reads "Draft failed", never stuck generating.
 */
class GeneratePage implements ShouldQueue
{
    use Queueable;

    public int $tries = 1;

    public int $timeout = 600;

    public function __construct(
        public readonly string $contentId,
        public readonly ?string $actorId = null,
        public readonly bool $autoPublish = false,
    ) {}

    /**
     * Stamp the page "generating" and enqueue. Named `enqueue`, NOT `queue` — a
     * `queue` method on a job is Laravel's reserved custom-queueing hook
     * (`$command->queue($queue, …)`), which would shadow this and pass the queue
     * connection as $page.
     *
     * $autoPublish chains a publish once the draft lands — the "Generate + publish"
     * batch path (bulk town pages). A clean draft is approved (which validates and
     * enqueues PublishContent); a thin/failed draft is left in the review queue by
     * approve's guard, never auto-published.
     */
    public static function enqueue(Content $page, ?string $actorId = null, bool $autoPublish = false): void
    {
        $page->markGenerating();

        self::dispatch($page->id, $actorId, $autoPublish);
    }

    public function handle(PageGenerator $generator): void
    {
        $page = Content::withoutGlobalScope(SiteScope::class)->find($this->contentId);

        if ($page === null) {
            return;
        }

        try {
            $generator->generate($page);
        } catch (DraftFailedException) {
            // Expected: the engine already recorded the failure (marker + cleared
            // generating) and logged. Swallow so the job succeeds and the row reads
            // "Draft failed" without burning the attempt as a job failure.
            return;
        }

        // Generate + publish: approve the fresh draft (validates, then enqueues the §2 publish).
        // Gated on hasDraft so an empty/thin result never auto-publishes — it stays in review.
        if ($this->autoPublish) {
            $page->refresh();
            if ($page->hasDraft()) {
                app(ReviewActions::class)->approve($page, $this->actorId);
            }
        }
    }

    /**
     * A dead job (timeout/re-reservation, or an unexpected throw) must not leave
     * the page stuck "Generating". Record the failure so it reads "Draft failed".
     */
    public function failed(?Throwable $exception): void
    {
        Content::withoutGlobalScope(SiteScope::class)
            ->find($this->contentId)
            ?->recordDraftFailure(DraftFailure::fromException($exception ?? new \RuntimeException('Generation job failed.')));
    }
}
