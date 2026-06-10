<?php

namespace App\ContentEngine\Review;

use App\Enums\ContentStatus;
use App\Enums\ReviewFlag;
use App\Jobs\PublishContent;
use App\Models\Content;

/**
 * The operator's review actions — the orchestration that closes the §6 → §2
 * pipeline. Approve validates (a required-image render_failed hard-blocks; an
 * unsupported claim warns), flips the draft to `approved`, and enqueues §2's
 * `PublishContent`. Reject, lock, edit-in-place, and bulk variants round it out.
 *
 * Pure orchestration over existing models + §2's publish entrypoint — no UI
 * here, so it is unit-testable with a faked job dispatch.
 */
class ReviewActions
{
    /**
     * Approve a draft and enqueue its publish. Blocked (no dispatch) if a
     * required image is render_failed.
     */
    public function approve(Content $content, ?string $actorId = null): ApproveResult
    {
        $blocker = $this->blockingReason($content);
        if ($blocker !== null) {
            return ApproveResult::blocked($blocker);
        }

        $warnings = $this->warnings($content);

        $content->forceFill(['status' => ContentStatus::Approved])->save();

        PublishContent::dispatch($content->id, $actorId);

        return ApproveResult::approved($warnings);
    }

    /**
     * Approve several drafts under the same publish-validation guard.
     *
     * @param  iterable<Content>  $contents
     * @return array<string, ApproveResult> keyed by content id
     */
    public function bulkApprove(iterable $contents, ?string $actorId = null): array
    {
        $results = [];
        foreach ($contents as $content) {
            $results[$content->id] = $this->approve($content, $actorId);
        }

        return $results;
    }

    public function reject(Content $content, string $reason): Content
    {
        $content->forceFill([
            'status' => ContentStatus::Rejected,
            'reject_reason' => $reason,
        ])->save();

        return $content;
    }

    /**
     * Lock the page so a republish never clobbers operator edits (§2 honors it).
     */
    public function lock(Content $content): Content
    {
        $content->forceFill(['locked' => true])->save();

        return $content;
    }

    /**
     * Persist in-place edits to slot content / body / SEO before approval.
     *
     * @param  array{slot_payload?: array<string, mixed>, body?: string|null, seo?: array<string, mixed>}  $edits
     */
    public function saveEdits(Content $content, array $edits): Content
    {
        $attributes = [];

        if (array_key_exists('slot_payload', $edits)) {
            $attributes['slot_payload'] = $edits['slot_payload'];
        }

        if (array_key_exists('body', $edits)) {
            $attributes['body'] = $edits['body'];
        }

        if (array_key_exists('seo', $edits)) {
            $meta = $content->meta ?? [];
            $meta['seo'] = array_merge(is_array($meta['seo'] ?? null) ? $meta['seo'] : [], $edits['seo']);
            $attributes['meta'] = $meta;
        }

        $content->forceFill($attributes)->save();

        return $content;
    }

    /**
     * The blocking reason that prevents approval, or null when clear. A required
     * image that won't render is the hard block (§2 won't publish a partial page).
     */
    public function blockingReason(Content $content): ?string
    {
        if (! $content->hasDraft()) {
            return 'This item has no completed draft yet — generate the post before approving.';
        }

        if (in_array(ReviewFlag::RenderFailed, AlertFlags::for($content), true)) {
            return 'A required image failed to render — reset/retry it before approving.';
        }

        return null;
    }

    /**
     * Non-blocking warnings surfaced on approve (the operator decides).
     *
     * @return list<string>
     */
    public function warnings(Content $content): array
    {
        $warnings = [];

        if (in_array(ReviewFlag::UnsupportedClaim, AlertFlags::for($content), true)) {
            $warnings[] = 'This draft has an unsupported claim that did not trace to the Claims set.';
        }

        return $warnings;
    }
}
