<?php

namespace App\Jobs;

use App\Models\Scopes\SiteScope;
use App\Models\SocialPost;
use App\Social\SocialSharer;
use Illuminate\Contracts\Queue\ShouldBeUniqueUntilProcessing;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Carbon;

/**
 * Sends one queued share through the provider. Two attempts: a transient provider failure (timeout, 5xx,
 * per-minute limit) is retried once after the backoff; anything else, or a second failure, lands the row
 * as `failed` and the panel retries by hand. A row whose `scheduled_for` moved into the future (the planner
 * rescheduled it) re-queues itself for the new time; the unique lock is released before processing so that
 * re-queue is not swallowed.
 */
class SendSocialPost implements ShouldBeUniqueUntilProcessing, ShouldQueue
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
        if ($post === null) {
            return;
        }
        // Rescheduled after this job was queued (the planner moved it): wake again at the new time instead.
        if ($post->scheduled_for !== null && $post->scheduled_for->greaterThan(Carbon::now()->addMinute())) {
            self::dispatch($this->postId)->delay($post->scheduled_for);

            return;
        }
        $sharer->send($post, lastAttempt: $this->attempts() >= $this->tries);
    }
}
