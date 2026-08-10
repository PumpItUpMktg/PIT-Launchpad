<?php

namespace App\Jobs;

use App\Models\Content;
use App\Models\Scopes\SiteScope;
use App\Publishing\PublishContentService;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

/**
 * The main publish job (Horizon): render → assemble → push to /content → record
 * state. Idempotent by ULID — a re-dispatch updates rather than duplicates — so
 * it is safely retryable. Transient WP failures are bounded by the REST client's
 * own retry; an exhausted push lands the content in publish_failed.
 *
 * Unique per content id ({@see ShouldBeUnique}): while a page already has a publish job queued or in
 * flight, further dispatches for it are DROPPED. Publishing is idempotent, so a repeated "Sync pages"
 * click or two overlapping re-push waves would otherwise stack a full duplicate pass per trigger (the
 * incident where 419 published pages produced ~1,660 publish jobs). A genuine later re-publish, once
 * this one clears, still goes through — the lock releases when the job finishes.
 */
class PublishContent implements ShouldBeUnique, ShouldQueue
{
    use Queueable;

    public int $tries = 3;

    /**
     * The unique lock's safety expiry: comfortably above a real publish's render+push runtime, so a job
     * that dies without releasing can't wedge future publishes of the same page for long. The lock is
     * released the moment the job finishes normally, so this ceiling is only a backstop.
     */
    public int $uniqueFor = 600;

    /**
     * @return list<int>
     */
    public function backoff(): array
    {
        return [10, 30, 60];
    }

    public function __construct(
        public readonly string $contentId,
        public readonly ?string $actorId = null,
    ) {}

    /** Dedupe key: one in-flight publish per page (ULID) — the guard against stacked re-push waves. */
    public function uniqueId(): string
    {
        return $this->contentId;
    }

    public function handle(PublishContentService $service): void
    {
        $content = Content::withoutGlobalScope(SiteScope::class)->find($this->contentId);

        if ($content !== null) {
            $service->publish($content, $this->actorId);
        }
    }
}
