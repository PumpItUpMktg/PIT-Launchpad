<?php

namespace App\Jobs;

use App\ContentEngine\Drafting\DraftFailedException;
use App\ContentEngine\Generation\PostGenerator;
use App\Models\Content;
use App\Models\Scopes\SiteScope;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

/**
 * Run the on-demand "Generate post" off the web request: the Filament action
 * dispatches this and returns immediately, and the worker executes the expensive
 * Sonnet draft + fal render with no FPM clock ticking. Models PublishContent.
 *
 * The row carries a "generating" marker (stamped at dispatch by ::queue) so the
 * surfaces show that state until the draft lands (→ needs_review) or fails
 * (→ draft_error). A failed draft is recorded by the engine, not retried — the
 * call is expensive and a budget/transport failure won't fix itself; the operator
 * re-triggers from the row. tries=1 for that reason.
 */
class GeneratePost implements ShouldQueue
{
    use Queueable;

    public int $tries = 1;

    public function __construct(
        public readonly string $contentId,
        public readonly ?string $marketId = null,
        public readonly ?string $actorId = null,
    ) {}

    /**
     * Stamp the row "generating" and enqueue. Marking before dispatch means the
     * surfaces show the generating state immediately, before the worker picks up.
     */
    public static function queue(Content $content, ?string $marketId = null, ?string $actorId = null): void
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
            // The engine already stamped the failure marker + logged. Clear the
            // generating flag so the row reads "Draft failed", not stuck generating.
            $content->clearGenerating();
        }
    }
}
