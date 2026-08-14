<?php

namespace App\JobCapture\Review;

use App\Enums\JobStatus;
use App\Models\Job;
use Illuminate\Database\Eloquent\Collection;

/**
 * The operator's Job Capture review queue (§8) — the jobs awaiting a human decision before anything can be
 * pushed to WordPress. Site-scoped (the ambient tenant scope), newest first. Kept a thin, testable read
 * model; the Filament resource renders over it.
 *
 * The actionable set is `review` (enhancement produced a write-up, needs approval) plus `captured` and
 * `enhancing` jobs — surfaced so a job that hasn't been enhanced yet, is mid-enhancement, or whose
 * enhancement errored/timed out (leaving it stuck at `enhancing`) stays visible and re-enhanceable rather
 * than lost. Without `enhancing` here, a job stranded by a failed model call would never appear.
 */
class JobReviewQueue
{
    /** @return Collection<int, Job> */
    public function jobs(): Collection
    {
        return Job::query()
            ->with(['city', 'county', 'jobTypes'])
            ->whereIn('status', [JobStatus::Review->value, JobStatus::Captured->value, JobStatus::Enhancing->value])
            ->orderByRaw('CASE status WHEN ? THEN 0 ELSE 1 END', [JobStatus::Review->value])
            ->latest()
            ->get();
    }

    /** Count of jobs awaiting a decision (review only — the true backlog). */
    public function count(): int
    {
        return Job::query()->where('status', JobStatus::Review->value)->count();
    }
}
