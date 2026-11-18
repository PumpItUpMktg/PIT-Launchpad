<?php

namespace App\Jobs;

use App\Models\Scopes\SiteScope;
use App\Models\SocialPost;
use App\Social\SocialSharer;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

/**
 * Sends one queued share through the provider. Two attempts: a transient provider failure (timeout, 5xx,
 * per-minute limit) is retried once after the backoff; anything else, or a second failure, lands the row
 * as `failed` and the panel retries by hand.
 */
class SendSocialPost implements ShouldBeUnique, ShouldQueue
{
    use Queueable;

    public int $tries = 2;

    public int $backoff = 600;

    public int $timeout = 120;

    public int $uniqueFor = 600;

    public function __construct(public readonly string $postId) {}

    public function uniqueId(): string
    {
        return $this->postId;
    }

    public function handle(SocialSharer $sharer): void
    {
        $post = SocialPost::withoutGlobalScope(SiteScope::class)->find($this->postId);
        if ($post !== null) {
            $sharer->send($post, lastAttempt: $this->attempts() >= $this->tries);
        }
    }
}
