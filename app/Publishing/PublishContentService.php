<?php

namespace App\Publishing;

use App\Enums\AuditAction;
use App\Enums\ContentKind;
use App\Enums\ContentSource;
use App\Enums\ContentStatus;
use App\Integrations\Wordpress\WordpressClientFactory;
use App\Integrations\Wordpress\WordpressException;
use App\Models\Content;
use App\Models\Scopes\SiteScope;
use App\Models\Site;
use App\PageBuilder\Validation\PublishEligibility;
use App\PageBuilder\Validation\ValidationResult;
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
    /**
     * The only statuses publish() will act on: approved (the normal entry) or
     * further along — the in-flight transitional states (a retry continues), the
     * retryable failures, and published (an idempotent re-push / refresh updates
     * the live page by ULID). A candidate / scored / drafted / needs_review /
     * in_review / rejected row is NEVER pushed. This is the single source of the
     * publishable set — LaunchOrchestrator filters on it too.
     *
     * @var list<ContentStatus>
     */
    public const PUBLISHABLE = [
        ContentStatus::Approved,
        ContentStatus::Rendering,
        ContentStatus::Publishing,
        ContentStatus::Published,
        ContentStatus::RenderFailed,
        ContentStatus::PublishFailed,
    ];

    public function __construct(
        private readonly RenderCoordinator $renders,
        private readonly MetaBlobAssembler $assembler,
        private readonly WordpressClientFactory $wordpress,
        private readonly Audit $audit,
        private readonly PublishEligibility $eligibility,
    ) {}

    public static function isPublishable(ContentStatus $status): bool
    {
        return in_array($status, self::PUBLISHABLE, true);
    }

    private function reasons(ValidationResult $result): string
    {
        return implode('; ', array_map(fn ($f) => $f->code->value, $result->failures));
    }

    public function publish(Content $content, ?string $actorId = null, ContentSource $source = ContentSource::Generated): PublishResult
    {
        // State guard (the desync fix): only publish from a publishable status. An
        // unreviewed row — candidate / scored / drafted / needs_review / in_review,
        // or a rejected one — must NEVER be pushed; a desynced dispatch on a
        // needs_review row published page 196. No-op WITHOUT mutating status, so the
        // row stays exactly where it is and the review flow is untouched.
        if (! self::isPublishable($content->status)) {
            return PublishResult::skipped(
                $content,
                'Content is '.$content->status->value.', not a publishable state; publish skipped.'
            );
        }

        // Operator-edit protection: never overwrite a locked / locally-edited page.
        if ($content->isPublishProtected()) {
            return $this->resolveSkip(
                $content,
                'Content is locked or locally edited in WordPress; publish skipped to protect operator edits.'
            );
        }

        // §3a review gate: a LOCATION page must know its market (fail closed) and
        // have ≥1 market-scoped/site-wide review; a service page publishes without
        // a review gate (its testimonial slot is conditional). A failing page is
        // parked in in_review by evaluateForPublish() — never pushed.
        if ($content->kind === ContentKind::Page) {
            $eligibility = $this->eligibility->evaluateForPublish($content);
            if ($eligibility->failed()) {
                return PublishResult::blocked($content, 'Publish eligibility failed: '.$this->reasons($eligibility));
            }
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
        $payload = $this->assembler->assemble($content, $outcome->jobs, $source);

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
