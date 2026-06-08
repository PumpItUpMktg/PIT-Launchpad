<?php

namespace App\Jobs;

use App\Models\Content;
use App\Models\Scopes\SiteScope;
use App\Publishing\PublishContentService;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

/**
 * The main publish job (Horizon): render → assemble → push to /content → record
 * state. Idempotent by ULID — a re-dispatch updates rather than duplicates — so
 * it is safely retryable. Transient WP failures are bounded by the REST client's
 * own retry; an exhausted push lands the content in publish_failed.
 */
class PublishContent implements ShouldQueue
{
    use Queueable;

    public int $tries = 3;

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

    public function handle(PublishContentService $service): void
    {
        $content = Content::withoutGlobalScope(SiteScope::class)->find($this->contentId);

        if ($content !== null) {
            $service->publish($content, $this->actorId);
        }
    }
}
