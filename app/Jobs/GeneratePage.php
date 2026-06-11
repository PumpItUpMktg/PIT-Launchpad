<?php

namespace App\Jobs;

use App\ContentEngine\Drafting\DraftFailedException;
use App\ContentEngine\Drafting\DraftFailure;
use App\ContentEngine\Generation\PageGenerator;
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
    ) {}

    /**
     * Stamp the page "generating" and enqueue. Named `enqueue`, NOT `queue` — a
     * `queue` method on a job is Laravel's reserved custom-queueing hook
     * (`$command->queue($queue, …)`), which would shadow this and pass the queue
     * connection as $page.
     */
    public static function enqueue(Content $page, ?string $actorId = null): void
    {
        $page->markGenerating();

        self::dispatch($page->id, $actorId);
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
