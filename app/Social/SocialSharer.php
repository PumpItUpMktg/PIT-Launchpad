<?php

namespace App\Social;

use App\Enums\SocialNetwork;
use App\Enums\SocialPostStatus;
use App\Enums\SocialTrigger;
use App\Jobs\SendSocialPost;
use App\Models\Content;
use App\Models\Job;
use App\Models\Scopes\SiteScope;
use App\Models\SocialChannel;
use App\Models\SocialPost;
use App\Social\Provider\SocialProvider;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Throwable;

/**
 * Queues and sends shares. The approval that already exists is the approval: a job share queues when the
 * job publishes, a post share when the post publishes (and again when a question refresh lands). Each
 * share is one row per enabled channel; a job's GBP share goes only to the GBP of the location that
 * serves its town ({@see LocationRouter}). Idempotent per (channel, subject, trigger) for the publish
 * triggers; the re-share triggers honour a per-post cooldown; a manual share always goes. Sending is a
 * queued job per row, delayed to the channel's cadence slot.
 */
final class SocialSharer
{
    public function __construct(
        private readonly SocialComposer $composer,
        private readonly LocationRouter $router,
        private readonly SocialProvider $provider,
    ) {}

    /**
     * @param  array<string, mixed>|null  $snapshot
     * @return list<SocialPost>
     */
    public function shareJob(Job $job, SocialTrigger $trigger, ?array $snapshot = null): array
    {
        $channels = $this->channels((string) $job->site_id);
        if ($channels->isEmpty()) {
            return [];
        }
        $location = $this->router->forJob($job);
        $out = [];
        foreach ($channels as $channel) {
            if ($channel->network->perLocation() && $channel->location_id !== null && $channel->location_id !== $location?->id) {
                continue;   // another location's Business Profile
            }
            if (! $this->allowed($channel, SocialPost::SUBJECT_JOB, (string) $job->id, $trigger)) {
                continue;
            }
            $copy = $this->composer->job($job, $channel->network, $trigger);
            $out[] = $this->queue($channel, SocialPost::SUBJECT_JOB, (string) $job->id, $trigger, $copy, $snapshot);
        }

        return $out;
    }

    /**
     * @param  array<string, mixed>|null  $snapshot
     * @return list<SocialPost>
     */
    public function sharePost(Content $post, SocialTrigger $trigger, ?array $snapshot = null): array
    {
        $channels = $this->channels((string) $post->site_id);
        if ($channels->isEmpty()) {
            return [];
        }
        if ($trigger === SocialTrigger::PostRefreshed && ! $this->refreshIsNew($post)) {
            return [];
        }
        $out = [];
        foreach ($channels as $channel) {
            if (! $this->allowed($channel, SocialPost::SUBJECT_POST, (string) $post->id, $trigger)) {
                continue;
            }
            $copy = $this->composer->post($post, $channel->network, $trigger);
            $out[] = $this->queue($channel, SocialPost::SUBJECT_POST, (string) $post->id, $trigger, $copy, $snapshot);
        }

        return $out;
    }

    /** Push one queued row through the provider; `sent` with the provider's id, or `failed` with the message. */
    public function send(SocialPost $post): void
    {
        if ($post->status !== SocialPostStatus::Queued) {
            return;
        }
        $channel = $post->channel()->withoutGlobalScope(SiteScope::class)->first();
        if ($channel === null || ! $channel->enabled) {
            $post->forceFill(['status' => SocialPostStatus::Skipped, 'error' => 'channel disabled'])->save();

            return;
        }
        try {
            $result = $this->provider->publish($channel, $post);
        } catch (Throwable $e) {
            $post->forceFill(['status' => SocialPostStatus::Failed, 'error' => mb_substr($e->getMessage(), 0, 1000)])->save();

            return;
        }
        $post->forceFill([
            'status' => SocialPostStatus::Sent,
            'provider_post_id' => $result->providerPostId,
            'permalink' => $result->permalink,
            'error' => null,
            'sent_at' => Carbon::now(),
        ])->save();
    }

    /** A failed row goes back to the queue and is sent again. */
    public function retry(SocialPost $post): void
    {
        if ($post->status !== SocialPostStatus::Failed) {
            return;
        }
        $post->forceFill(['status' => SocialPostStatus::Queued, 'error' => null, 'scheduled_for' => Carbon::now()])->save();
        SendSocialPost::dispatch((string) $post->id);
    }

    /** @return Collection<int, SocialChannel> */
    private function channels(string $siteId): Collection
    {
        if (! (bool) config('launchpad.social.enabled', true)) {
            return new Collection;
        }

        return SocialChannel::withoutGlobalScope(SiteScope::class)
            ->where('site_id', $siteId)
            ->where('enabled', true)
            ->orderBy('network')
            ->get();
    }

    private function allowed(SocialChannel $channel, string $subjectType, string $subjectId, SocialTrigger $trigger): bool
    {
        $rows = SocialPost::withoutGlobalScope(SiteScope::class)
            ->where('social_channel_id', $channel->id)
            ->where('subject_type', $subjectType)
            ->where('subject_id', $subjectId);

        return match ($trigger) {
            // The publish-day shares happen once per channel — a re-push of the same job/post never re-shares.
            SocialTrigger::JobPublished, SocialTrigger::PostPublished => ! (clone $rows)->where('trigger', $trigger->value)->where('status', '!=', SocialPostStatus::Failed->value)->exists(),
            // A refresh re-share needs the refresh to be newer than the last share (checked per post above)
            // and no share of this post on this channel inside the cooldown.
            SocialTrigger::PostRefreshed, SocialTrigger::Momentum, SocialTrigger::Weather => ! (clone $rows)
                ->whereIn('status', [SocialPostStatus::Queued->value, SocialPostStatus::Sent->value])
                ->where('created_at', '>=', Carbon::now()->subDays((int) config('launchpad.social.reshare_cooldown_days', 60)))
                ->exists(),
            SocialTrigger::Manual => true,
        };
    }

    /** A refresh share only fires when the post's question refresh landed after its last share. */
    private function refreshIsNew(Content $post): bool
    {
        $meta = is_array($post->meta) ? $post->meta : [];
        $refreshedAt = $meta['question_refresh']['refreshed_at'] ?? null;
        if (! is_string($refreshedAt) || $refreshedAt === '') {
            return false;
        }
        $last = SocialPost::withoutGlobalScope(SiteScope::class)
            ->where('subject_type', SocialPost::SUBJECT_POST)
            ->where('subject_id', $post->id)
            ->whereIn('status', [SocialPostStatus::Queued->value, SocialPostStatus::Sent->value])
            ->max('created_at');

        return $last === null || Carbon::parse($refreshedAt)->greaterThan(Carbon::parse((string) $last));
    }

    /** @param  array<string, mixed>|null  $snapshot */
    private function queue(SocialChannel $channel, string $subjectType, string $subjectId, SocialTrigger $trigger, SocialCopy $copy, ?array $snapshot): SocialPost
    {
        $when = $this->slot($channel);
        $post = SocialPost::withoutGlobalScope(SiteScope::class)->create([
            'site_id' => $channel->site_id,
            'social_channel_id' => $channel->id,
            'subject_type' => $subjectType,
            'subject_id' => $subjectId,
            'network' => $channel->network,
            'trigger' => $trigger,
            'status' => SocialPostStatus::Queued,
            'copy' => $copy->copy,
            'hashtags' => $copy->hashtags,
            'image_url' => $copy->imageUrl,
            'link' => $copy->link,
            'cta' => $copy->cta,
            'scheduled_for' => $when,
            'snapshot' => $snapshot,
        ]);
        $job = SendSocialPost::dispatch((string) $post->id);
        if ($when->isFuture()) {
            $job->delay($when);
        }

        return $post;
    }

    /**
     * The channel's cadence slot: now when the channel has no daily cap or room left today; otherwise the
     * first later day with room, at the configured hour. A capped GBP never buries itself under six jobs.
     */
    private function slot(SocialChannel $channel): Carbon
    {
        $cap = $channel->max_per_day;
        $now = Carbon::now();
        if ($cap === null || $cap <= 0) {
            return $now;
        }
        for ($d = 0; $d < 30; $d++) {
            $day = $now->copy()->addDays($d);
            $count = SocialPost::withoutGlobalScope(SiteScope::class)
                ->where('social_channel_id', $channel->id)
                ->whereIn('status', [SocialPostStatus::Queued->value, SocialPostStatus::Sent->value])
                ->whereBetween('scheduled_for', [$day->copy()->startOfDay(), $day->copy()->endOfDay()])
                ->count();
            if ($count < $cap) {
                return $d === 0 ? $now : $day->copy()->startOfDay()->setHour((int) config('launchpad.social.slot_hour', 14));
            }
        }

        return $now->copy()->addDays(30)->startOfDay()->setHour((int) config('launchpad.social.slot_hour', 14));
    }

    /** The networks a site has enabled — for the boards' "shares to" line. @return list<SocialNetwork> */
    public function networksFor(string $siteId): array
    {
        return $this->channels($siteId)->map(fn (SocialChannel $c): SocialNetwork => $c->network)->unique()->values()->all();
    }
}
