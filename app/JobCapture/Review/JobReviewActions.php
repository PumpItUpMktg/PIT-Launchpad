<?php

namespace App\JobCapture\Review;

use App\Enums\JobStatus;
use App\JobCapture\Capture\ClientDisplayName;
use App\JobCapture\Types\JobTypeVocabulary;
use App\Jobs\EnhanceJob;
use App\Jobs\PublishJob;
use App\Jobs\UnpublishJob;
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
    public function __construct(private readonly JobTypeVocabulary $vocabulary) {}

    /** The operator-editable fields on the review screen (raw_description is intentionally NOT here). */
    private const EDITABLE = ['source_description', 'enhanced_description', 'post_title', 'meta_description'];

    /** Whether this job may be approved right now — reviewed AND carrying a write-up. */
    public function canApprove(Job $job): bool
    {
        return $job->status === JobStatus::Review && $job->hasDraft();
    }

    /**
     * Approve a reviewed job → `approved` and enqueue the §9 WordPress push ({@see PublishJob}, idempotent
     * by ULID). Returns false (no state change, no push) when the job isn't approvable.
     */
    public function approve(Job $job): bool
    {
        if (! $this->canApprove($job)) {
            return false;
        }

        $job->forceFill(['status' => JobStatus::Approved, 'reject_reason' => null])->save();

        PublishJob::dispatch($job->id);

        return true;
    }

    /**
     * Reject a reviewed job with an optional reason. If the job was already live on WordPress, its post is
     * pulled DOWN ({@see UnpublishJob}) so a rejection never orphans a published post (§9).
     */
    public function reject(Job $job, ?string $reason = null): void
    {
        $wasPublished = $job->wp_post_id !== null;

        $reason = trim((string) $reason);
        $job->forceFill([
            'status' => JobStatus::Rejected,
            'reject_reason' => $reason !== '' ? $reason : null,
        ])->save();

        if ($wasPublished) {
            UnpublishJob::dispatch($job->id);
        }
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
     * alt text, the client name, the work date, the applied services). No AI call — that is
     * {@see reEnhance()}. Only whitelisted fields are written.
     *
     * @param  array<string, mixed>  $edits  any of EDITABLE, plus `primary_photo_index`, `alts` (list<string>),
     *                                       `client_name_full` (display name re-derived), `performed_at` (Y-m-d|null),
     *                                       and `job_types` (list of labels or {label, slug?, job_type_id?})
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

        if (array_key_exists('client_name_full', $edits)) {
            $full = trim((string) $edits['client_name_full']);
            $fill['client_name_full'] = $full !== '' ? $full : null;
            $fill['client_name_display'] = ClientDisplayName::from($full);
        }

        if (array_key_exists('performed_at', $edits)) {
            $date = trim((string) $edits['performed_at']);
            $fill['performed_at'] = preg_match('/^\d{4}-\d{2}-\d{2}$/', $date) === 1 ? $date : null;
        }

        if ($fill !== []) {
            $job->forceFill($fill)->save();
        }

        if (array_key_exists('job_types', $edits) && is_array($edits['job_types'])) {
            $this->vocabulary->apply($job, array_values($edits['job_types']));
        }
    }
}
