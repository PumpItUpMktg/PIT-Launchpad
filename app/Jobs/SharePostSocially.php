<?php

namespace App\Jobs;

use App\Enums\ContentKind;
use App\Enums\ContentStatus;
use App\Enums\SocialTrigger;
use App\Models\Content;
use App\Models\Scopes\SiteScope;
use App\Social\SocialSharer;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

/** Queues a live post's shares. Dispatched by the content publisher on first publish and on a refresh. */
class SharePostSocially implements ShouldQueue
{
    use Queueable;

    public int $tries = 1;

    public function __construct(public readonly string $contentId, public readonly string $trigger = SocialTrigger::PostPublished->value) {}

    public function handle(SocialSharer $sharer): void
    {
        $post = Content::withoutGlobalScope(SiteScope::class)->with(['site', 'silo', 'renderJobs'])->find($this->contentId);
        if ($post === null || $post->kind !== ContentKind::Post || $post->status !== ContentStatus::Published) {
            return;
        }
        $sharer->sharePost($post, SocialTrigger::from($this->trigger));
    }
}
