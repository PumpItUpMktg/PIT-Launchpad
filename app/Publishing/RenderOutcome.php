<?php

namespace App\Publishing;

use App\Models\RenderJob;
use Illuminate\Support\Collection;

/**
 * The result of rendering a content's images: whether every required image
 * succeeded, and the slots of any required image that ended render_failed (which
 * block publish — no partial page with a broken hero).
 */
final class RenderOutcome
{
    /**
     * @param  Collection<int, RenderJob>  $jobs
     * @param  list<string>  $failedRequiredSlots
     */
    public function __construct(
        public readonly Collection $jobs,
        public readonly bool $allRequiredRendered,
        public readonly array $failedRequiredSlots,
    ) {}

    public function isBlocked(): bool
    {
        return ! $this->allRequiredRendered;
    }
}
