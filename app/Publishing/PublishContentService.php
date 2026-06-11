<?php

namespace App\Publishing;

use App\Enums\AuditAction;
use App\Enums\ContentStatus;
use App\Integrations\Wordpress\WordpressClientFactory;
use App\Integrations\Wordpress\WordpressException;
use App\Models\Content;
use App\Models\Scopes\SiteScope;
use App\Models\Site;
use App\Security\Audit;

/**
 * The publish entrypoint §6c's approve action calls. It drives the state machine
 * approved → rendering → publishing → published, with render_failed / publish_failed
 * as surfaced branches:
 *
 *  - honors the lock / locally-edited flag (skip, never clobber operator edits);
 *  - ensures every required image is rendered (a failed required image blocks);
 *  - assembles the consolidated meta-blob and upserts it to /content by ULID;
 *  - stores wp_post_id, flips to published, and fires §9's ContentPublished
 *    audit row (secret-free).
 *
 * §2 ends here — "pushed to WP / state recorded."
 */
class PublishContentService
{
    public function __construct(
        private readonly RenderCoordinator $renders,
        private readonly MetaBlobAssembler $assembler,
        private readonly WordpressClientFactory $wordpress,
        private readonly Audit $audit,
    ) {}

    public function publish(Content $content, ?string $actorId = null): PublishResult
    {
        // Operator-edit protection: never overwrite a locked / locally-edited page.
        if ($content->isPublishProtected()) {
            return $this->resolveSkip(
                $content,
                'Content is locked or locally edited in WordPress; publish skipped to protect operator edits.'
            );
        }

        $content->forceFill(['status' => ContentStatus::Rendering])->save();

        $outcome = $this->renders->render($content);

        if ($outcome->isBlocked()) {
            $message = 'Required image(s) failed to render: '.implode(', ', $outcome->failedRequiredSlots);
            $content->forceFill([
                'status' => ContentStatus::RenderFailed,
                'last_publish_error' => $message,
            ])->save();

            return PublishResult::blocked($content, $message);
        }

        $content->forceFill(['status' => ContentStatus::Publishing])->save();

        $site = Site::withoutGlobalScope(SiteScope::class)->findOrFail($content->site_id);
        $payload = $this->assembler->assemble($content, $outcome->jobs);

        try {
            $response = $this->wordpress->forSite($site)->upsertContent($payload);
        } catch (WordpressException $e) {
            $content->forceFill([
                'status' => ContentStatus::PublishFailed,
                'last_publish_error' => $e->getMessage(),
            ])->save();

            return PublishResult::failed($content, $e->getMessage());
        }

        // The plugin upserts by ULID and reports a skip when the page is locked
        // in WordPress — honor it as a locally-edited signal, then resolve the
        // transitional status (the live page stays; never strand at publishing).
        if (! empty($response['skipped'])) {
            $content->forceFill(['locally_edited' => true])->save();

            return $this->resolveSkip($content, 'WordPress reports the page is locked; not overwritten.');
        }

        $wpPostId = (int) ($response['wp_post_id'] ?? 0);
        $content->forceFill([
            'wp_post_id' => $wpPostId,
            'status' => ContentStatus::Published,
            'published_at' => now(),
            'last_publish_error' => null,
        ])->save();

        $this->audit->log(AuditAction::ContentPublished, $content, $actorId, [
            'wp_post_id' => $wpPostId,
            'slug' => $content->slug,
        ]);

        return PublishResult::published($content, $wpPostId);
    }

    /**
     * Resolve a by-design skip: the live page is kept (push declined), so the row
     * returns to published rather than stranding in rendering/publishing — every
     * transitional state needs an exit for every outcome, not just success. The
     * skip reason is surfaced on last_publish_error (and carried in the result for
     * the UI notification); published_at is preserved if the page was already live.
     */
    private function resolveSkip(Content $content, string $message): PublishResult
    {
        $content->forceFill([
            'status' => ContentStatus::Published,
            'published_at' => $content->published_at ?? now(),
            'last_publish_error' => $message,
        ])->save();

        return PublishResult::skipped($content, $message);
    }
}
