<?php

namespace App\Jobs;

use App\ContentEngine\Drafting\DraftFailedException;
use App\ContentEngine\Generation\PageGenerator;
use App\Models\Content;
use App\Models\Scopes\SiteScope;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

/**
 * Run "Generate page" off the web request: the Filament action dispatches this
 * and returns immediately; the worker runs the expensive Sonnet draft + fal
 * render with no FPM clock. The page-kind analog of GeneratePost — same
 * generating-marker + tries=1 (a failed draft records its cause; the operator
 * re-triggers rather than auto-retrying an expensive call).
 */
class GeneratePage implements ShouldQueue
{
    use Queueable;

    public int $tries = 1;

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
            // The engine stamped the failure marker + logged; clear the generating
            // flag so the row reads "Draft failed", not stuck generating.
            $page->clearGenerating();
        }
    }
}
