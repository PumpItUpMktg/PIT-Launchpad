<?php

namespace App\ContentEngine\Review;

use App\Enums\ContentStatus;
use App\Enums\EditReason;
use App\Enums\ReviewFlag;
use App\Jobs\PublishContent;
use App\Models\Content;

/**
 * The operator's review actions — the orchestration that closes the §6 → §2
 * pipeline. The flow is two distinct gates (decoupled, per the build-out UX):
 * **Approve** accepts a draft into `approved` (a Launchpad-only acceptance — no
 * WordPress contact); **Publish** is the separate, heavier compose-and-push step
 * that enqueues §2's `PublishContent`. Reject, lock, edit-in-place, and bulk
 * variants round it out.
 *
 * Pure orchestration over existing models + §2's publish entrypoint — no UI
 * here, so it is unit-testable with a faked job dispatch.
 */
class ReviewActions
{
    public function __construct(private readonly EditCapture $editCapture = new EditCapture) {}

    /**
     * Approve a draft — accept it into `approved` (ready to publish). This does NOT push to
     * WordPress; publishing is the separate {@see publish()} step. Blocked (no flip) if a required
     * image is render_failed.
     */
    public function approve(Content $content, ?string $actorId = null): ApproveResult
    {
        $blocker = $this->blockingReason($content);
        if ($blocker !== null) {
            return ApproveResult::blocked($blocker);
        }

        $warnings = $this->warnings($content);

        $content->forceFill(['status' => ContentStatus::Approved])->save();

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

    /**
     * Publish an approved page — the compose-and-push. Enqueues §2's idempotent `PublishContent`
     * (compose into the Elementor template + brand kit, then push to WordPress). Re-checks the same
     * blocking guard so a render_failed page can never push a partial page.
     */
    public function publish(Content $content, ?string $actorId = null): ApproveResult
    {
        $blocker = $this->blockingReason($content);
        if ($blocker !== null) {
            return ApproveResult::blocked($blocker);
        }

        $warnings = $this->warnings($content);

        PublishContent::dispatch($content->id, $actorId);

        return ApproveResult::approved($warnings);
    }

    /**
     * Publish several approved pages — dispatches N queued compose-and-push jobs (a real batch of
     * background work with per-item status, not an instant flip).
     *
     * @param  iterable<Content>  $contents
     * @return array<string, ApproveResult> keyed by content id
     */
    public function bulkPublish(iterable $contents, ?string $actorId = null): array
    {
        $results = [];
        foreach ($contents as $content) {
            $results[$content->id] = $this->publish($content, $actorId);
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
     * Persist in-place edits to slot content / body / SEO before approval. When a reason tag is
     * supplied (the proof editor's one-tap prompt), the §7 quality signal is captured field-by-
     * field — the ORIGINAL value before this save overwrites it — so the signal is never lost.
     *
     * @param  array{slot_payload?: array<string, mixed>, body?: string|null, seo?: array<string, mixed>}  $edits
     */
    public function saveEdits(Content $content, array $edits, ?EditReason $reason = null, ?string $userId = null): Content
    {
        if ($reason !== null) {
            [$before, $after] = $this->editDiffMaps($content, $edits);
            $this->editCapture->captureDiff($content, $before, $after, $reason, $userId);
        }

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
     * Flatten the current vs incoming edit into before/after field maps (slot:<key> | body |
     * seo:<key>) for capture — read from $content BEFORE the save overwrites it.
     *
     * @param  array{slot_payload?: array<string, mixed>, body?: string|null, seo?: array<string, mixed>}  $edits
     * @return array{0: array<string, string|null>, 1: array<string, string|null>}
     */
    private function editDiffMaps(Content $content, array $edits): array
    {
        $before = [];
        $after = [];

        if (array_key_exists('slot_payload', $edits)) {
            $current = is_array($content->slot_payload) ? $content->slot_payload : [];
            foreach ($edits['slot_payload'] as $key => $value) {
                $before["slot:{$key}"] = $this->stringify($current[$key] ?? null);
                $after["slot:{$key}"] = $this->stringify($value);
            }
        }

        if (array_key_exists('body', $edits)) {
            $before['body'] = $this->stringify($content->body);
            $after['body'] = $this->stringify($edits['body']);
        }

        if (array_key_exists('seo', $edits)) {
            $currentSeo = is_array($content->meta['seo'] ?? null) ? $content->meta['seo'] : [];
            foreach ($edits['seo'] as $key => $value) {
                $before["seo:{$key}"] = $this->stringify($currentSeo[$key] ?? null);
                $after["seo:{$key}"] = $this->stringify($value);
            }
        }

        return [$before, $after];
    }

    private function stringify(mixed $value): ?string
    {
        if ($value === null || is_string($value)) {
            return $value;
        }

        return (string) json_encode($value, JSON_UNESCAPED_SLASHES);
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
