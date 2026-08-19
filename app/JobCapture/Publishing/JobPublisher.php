<?php

namespace App\JobCapture\Publishing;

use App\Enums\JobStatus;
use App\Integrations\Wordpress\WordpressClientFactory;
use App\Integrations\Wordpress\WordpressException;
use App\Jobs\PingJobIndexNow;
use App\Models\Job;

/**
 * Pushes an approved job to WordPress (§9) — the close of the pipeline. Laravel is authoritative: an
 * operator's edits overwrite WP on the next push, matched on the ULID (never a title match, which would
 * duplicate on the first edit). Idempotent by ULID, so a re-publish updates the same post. `approved →
 * publishing → published` stores the `wp_post_id`; a WordPress failure lands the job in `publish_failed`
 * with the error surfaced. Only a drafted job publishes (an empty post can never reach WordPress).
 */
final class JobPublisher
{
    public function __construct(
        private readonly WordpressClientFactory $wordpress,
        private readonly JobMetaBlobAssembler $assembler,
    ) {}

    public function publish(Job $job): void
    {
        if (! $job->hasDraft()) {
            return;
        }

        $job->forceFill(['status' => JobStatus::Publishing])->save();

        try {
            $response = $this->wordpress->forSite($job->site)->upsertJob($this->assembler->assemble($job));
        } catch (WordpressException $e) {
            $job->forceFill([
                'status' => JobStatus::PublishFailed,
                'last_publish_error' => $e->getMessage(),
            ])->save();

            return;
        }

        $job->forceFill([
            'status' => JobStatus::Published,
            'wp_post_id' => (int) ($response['wp_post_id'] ?? 0),
            'last_publish_error' => null,
        ])->save();

        // Tell IndexNow (Bing/Yandex/…) to crawl the new job URL — same publish-time hook + config gate as
        // §2 content publishing. Off the critical path; a failed ping never affects the publish.
        if (config('services.indexnow.ping_on_publish')) {
            PingJobIndexNow::dispatch($job->id);
        }
    }

    /**
     * Pull a published job's post DOWN (the unapprove path — §9): the plugin unpublishes the ULID's post
     * rather than orphaning it live, then the local `wp_post_id` is cleared. Best-effort — a transport
     * failure leaves the row for a retry rather than throwing.
     */
    public function unpublish(Job $job): void
    {
        try {
            $this->wordpress->forSite($job->site)->deleteJob($job->id);
        } catch (WordpressException) {
            // best-effort; the operator can retry the pull-down
        }

        $job->forceFill(['wp_post_id' => null])->save();
    }
}
