<?php

namespace App\Jobs;

use App\Models\RenderJob;
use App\Models\Scopes\SiteScope;
use App\Publishing\ImageRenderer;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

/**
 * Renders one image spec on the queue (Horizon). A job-level timeout guards the
 * whole render the way the fal adapter's HTTP timeout guards each call; the
 * renderer itself bounds retries to a render_failed terminal, so the job does
 * not loop forever.
 */
class RenderImage implements ShouldQueue
{
    use Queueable;

    /** A render must never hang — cap the whole job. */
    public int $timeout = 180;

    public int $tries = 1;

    public function __construct(
        public readonly string $renderJobId,
    ) {}

    public function handle(ImageRenderer $renderer): void
    {
        $job = RenderJob::withoutGlobalScope(SiteScope::class)->find($this->renderJobId);

        if ($job !== null) {
            $renderer->render($job);
        }
    }
}
