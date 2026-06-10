<?php

namespace App\Publishing;

use App\Models\Content;

/**
 * The per-post publish — the single-post analog of the launch orchestrator. Gated
 * on the same verified, non-compromised WordPress connection as the bulk launch,
 * then delegates to the proven PublishContentService (render → assemble → upsert
 * by content_id), which already honors {skipped:true} and idempotent re-publish.
 */
class PostPublisher
{
    public function __construct(
        private readonly ConnectionGate $gate,
        private readonly PublishContentService $publisher,
    ) {}

    public function publish(Content $content, ?string $actorId = null): PublishResult
    {
        if (! $this->gate->hasVerifiedWordpress($content->site_id)) {
            return PublishResult::failed(
                $content,
                'No present, non-compromised WordPress connection — wire and verify one first.',
            );
        }

        return $this->publisher->publish($content, $actorId);
    }
}
