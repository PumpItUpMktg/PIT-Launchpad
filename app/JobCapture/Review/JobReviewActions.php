<?php

namespace App\JobCapture\Review;

use App\Enums\JobStatus;
use App\Jobs\EnhanceJob;
use App\Models\Job;

/**
 * The operator's review actions for Job Capture (§8) — pure orchestration over the {@see Job} model, no UI,
 * so it is unit-testable. Nothing here pushes to WordPress: **Approve** accepts a reviewed job into
 * `approved` (the §9 publish pipeline, wired in a later phase, is what pushes an approved job). The other
 * actions — reject with a reason, re-enhance, and edit-in-place — round out the review screen.
 *
 * Approve is gated on {@see Job::hasDraft()}: an un-enhanced job can never be approved, so an empty post can
 * never reach WordPress (mirrors §6c's drafted-vs-undrafted gate). Editing routes through the source of
 * truth for the AI seed — `source_description`, never `raw_description` — so an edit + re-enhance corrects a
 * bad pass without compounding drift.
 */
class JobReviewActions
{
    /** The operator-editable fields on the review screen (raw_description is intentionally NOT here). */
    private const EDITABLE = ['source_description', 'enhanced_description', 'post_title', 'meta_description'];

    /** Whether this job may be approved right now — reviewed AND carrying a write-up. */
    public function canApprove(Job $job): bool
    {
        return $job->status === JobStatus::Review && $job->hasDraft();
    }

    /**
     * Approve a reviewed job → `approved` (ready to publish). No WordPress push here — that is the §9
     * pipeline. Returns false (no state change) when the job isn't approvable.
     */
    public function approve(Job $job): bool
    {
        if (! $this->canApprove($job)) {
            return false;
        }

        $job->forceFill(['status' => JobStatus::Approved, 'reject_reason' => null])->save();

        return true;
    }

    /** Reject a reviewed job with an optional reason (the tech/operator sees why). */
    public function reject(Job $job, ?string $reason = null): void
    {
        $reason = trim((string) $reason);
        $job->forceFill([
            'status' => JobStatus::Rejected,
            'reject_reason' => $reason !== '' ? $reason : null,
        ])->save();
    }

    /**
     * Re-run enhancement (§7) against the current `source_description` — the operator's "Re-enhance" after
     * editing the seed. Queued (off-request), never blocking; distinct from a plain "Save edits".
     */
    public function reEnhance(Job $job): void
    {
        EnhanceJob::dispatch($job->id);
    }

    /**
     * Save an operator's in-place edits (wording fixes, a corrected AI seed, primary-photo choice, per-photo
     * alt text). No AI call — that is {@see reEnhance()}. Only whitelisted fields are written.
     *
     * @param  array<string, mixed>  $edits  any of EDITABLE, plus `primary_photo_index` and `alts` (list<string>)
     */
    public function saveEdits(Job $job, array $edits): void
    {
        $fill = [];
        foreach (self::EDITABLE as $field) {
            if (array_key_exists($field, $edits)) {
                $fill[$field] = $edits[$field] === null ? null : (string) $edits[$field];
            }
        }

        if (array_key_exists('primary_photo_index', $edits)) {
            $fill['primary_photo_index'] = max(0, (int) $edits['primary_photo_index']);
        }

        if (array_key_exists('alts', $edits) && is_array($edits['alts'])) {
            $photos = is_array($job->photos) ? $job->photos : [];
            foreach (array_values($edits['alts']) as $i => $alt) {
                if (isset($photos[$i]) && is_string($alt)) {
                    $photos[$i]['alt'] = trim($alt);
                }
            }
            $fill['photos'] = $photos ?: null;
        }

        if ($fill !== []) {
            $job->forceFill($fill)->save();
        }
    }
}
