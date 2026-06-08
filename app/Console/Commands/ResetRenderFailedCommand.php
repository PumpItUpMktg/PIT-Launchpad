<?php

namespace App\Console\Commands;

use App\Enums\RenderStatus;
use App\Jobs\RenderImage;
use App\Models\RenderJob;
use App\Models\Scopes\SiteScope;
use Illuminate\Console\Command;

/**
 * Operator recovery for render_failed images: resets the terminal jobs back to
 * queued (clearing attempts/error) and re-dispatches them. Scoped to one content
 * or run across every render_failed job. Never rotates or republishes on its own.
 */
class ResetRenderFailedCommand extends Command
{
    protected $signature = 'launchpad:reset-render
        {content? : Restrict to one Content id (default: all render_failed jobs)}';

    protected $description = 'Reset and requeue render_failed images for retry.';

    public function handle(): int
    {
        $query = RenderJob::withoutGlobalScope(SiteScope::class)
            ->where('status', RenderStatus::RenderFailed->value);

        $contentId = $this->argument('content');
        if ($contentId !== null) {
            $query->where('content_id', $contentId);
        }

        $jobs = $query->get();

        if ($jobs->isEmpty()) {
            $this->info('No render_failed images to reset.');

            return self::SUCCESS;
        }

        foreach ($jobs as $job) {
            $job->forceFill([
                'status' => RenderStatus::Queued,
                'attempts' => 0,
                'error' => null,
            ])->save();

            RenderImage::dispatch($job->id);
        }

        $this->info("Reset and requeued {$jobs->count()} image(s).");

        return self::SUCCESS;
    }
}
