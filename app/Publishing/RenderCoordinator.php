<?php

namespace App\Publishing;

use App\Enums\RenderStatus;
use App\Models\Content;
use App\Models\RenderJob;
use App\Models\Scopes\SiteScope;
use Illuminate\Support\Collection;

/**
 * Bridges §6b's emitted image specs to the render pipeline: ensures one
 * RenderJob per spec, renders any not yet succeeded, and reports whether the
 * content is clear to publish (every required image rendered) or blocked (a
 * required image is render_failed).
 */
class RenderCoordinator
{
    public function __construct(
        private readonly ImageRenderer $renderer,
    ) {}

    public function render(Content $content): RenderOutcome
    {
        $jobs = $this->ensureJobs($content);

        foreach ($jobs as $job) {
            if (! $job->isRendered() && ! $job->hasFailed()) {
                $this->renderer->render($job);
            }
        }

        $failedRequired = $jobs
            ->filter(fn (RenderJob $j) => $j->required && $j->hasFailed())
            ->map(fn (RenderJob $j) => (string) $j->slot)
            ->values()
            ->all();

        $allRequiredRendered = $jobs
            ->filter(fn (RenderJob $j) => $j->required)
            ->every(fn (RenderJob $j) => $j->isRendered());

        return new RenderOutcome($jobs, $allRequiredRendered, $failedRequired);
    }

    /**
     * @return Collection<int, RenderJob>
     */
    private function ensureJobs(Content $content): Collection
    {
        $specs = is_array($content->meta['image_specs'] ?? null) ? $content->meta['image_specs'] : [];
        $kit = $content->wireframe_kit_id !== null ? $content->wireframeKit?->schema() : null;

        $jobs = new Collection;

        foreach ($specs as $spec) {
            $slot = isset($spec['slot']) ? (string) $spec['slot'] : '';
            if ($slot === '') {
                continue;
            }

            $job = RenderJob::withoutGlobalScope(SiteScope::class)
                ->where('content_id', $content->id)
                ->where('slot', $slot)
                ->first();

            if ($job === null) {
                $required = $kit?->slot($slot)?->isRequired() ?? true;

                $job = RenderJob::create([
                    'site_id' => $content->site_id,
                    'content_id' => $content->id,
                    'slot' => $slot,
                    'prompt' => (string) ($spec['prompt'] ?? ''),
                    'seo_filename' => $spec['seo_filename'] ?? null,
                    'alt' => $spec['alt'] ?? null,
                    'title' => $spec['title'] ?? null,
                    'caption' => $spec['caption'] ?? null,
                    'required' => $required,
                    'provider' => 'fal',
                    'status' => RenderStatus::Queued,
                ]);
            }

            $jobs->push($job);
        }

        return $jobs;
    }
}
