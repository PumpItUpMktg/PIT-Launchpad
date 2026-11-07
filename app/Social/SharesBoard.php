<?php

namespace App\Social;

use App\Enums\ContentKind;
use App\Enums\ContentStatus;
use App\Enums\JobStatus;
use App\Enums\SocialPostStatus;
use App\Models\Content;
use App\Models\Job;
use App\Models\Location;
use App\Models\Scopes\SiteScope;
use App\Models\SocialChannel;
use App\Models\SocialPost;
use Illuminate\Support\Carbon;

/** Read model for the Operate → Shares page: the site's channels, its recent shares, and what it could share by hand. */
final class SharesBoard
{
    public function __construct(private readonly SocialComposer $composer) {}

    /** @return list<array<string, mixed>> */
    public function channels(string $siteId): array
    {
        return SocialChannel::withoutGlobalScope(SiteScope::class)
            ->where('site_id', $siteId)
            ->with('location')
            ->orderBy('network')->orderBy('created_at')
            ->get()
            ->map(fn (SocialChannel $c): array => [
                'id' => (string) $c->id,
                'network' => $c->network->value,
                'network_label' => $c->network->label(),
                'label' => $c->displayLabel(),
                'location' => $c->location?->name,
                'provider_channel_id' => $c->provider_channel_id,
                'enabled' => $c->enabled,
                'max_per_day' => $c->max_per_day,
                'sent' => $c->posts()->where('status', SocialPostStatus::Sent->value)->count(),
                'failed' => $c->posts()->where('status', SocialPostStatus::Failed->value)->count(),
            ])->values()->all();
    }

    /** @return array<string, string> location id → name, for the GBP channel form */
    public function locationOptions(string $siteId): array
    {
        return Location::withoutGlobalScope(SiteScope::class)
            ->where('site_id', $siteId)->whereNull('merged_into_id')
            ->orderBy('name')->pluck('name', 'id')->all();
    }

    /** @return list<array<string, mixed>> */
    public function shares(string $siteId, int $limit = 60): array
    {
        $rows = SocialPost::withoutGlobalScope(SiteScope::class)
            ->where('site_id', $siteId)
            ->with('channel.location')
            ->orderByDesc('created_at')
            ->limit($limit)
            ->get();
        $jobs = Job::withoutGlobalScope(SiteScope::class)->whereIn('id', $rows->where('subject_type', SocialPost::SUBJECT_JOB)->pluck('subject_id'))->get()->keyBy('id');
        $posts = Content::withoutGlobalScope(SiteScope::class)->whereIn('id', $rows->where('subject_type', SocialPost::SUBJECT_POST)->pluck('subject_id'))->get()->keyBy('id');

        return $rows->map(function (SocialPost $p) use ($jobs, $posts): array {
            $subject = $p->subject_type === SocialPost::SUBJECT_JOB ? $jobs->get($p->subject_id) : $posts->get($p->subject_id);
            $title = $subject instanceof Job ? ($subject->post_title ?: 'Job') : ($subject instanceof Content ? $subject->title : '(removed)');

            return [
                'id' => (string) $p->id,
                'subject_type' => $p->subject_type,
                'subject_title' => (string) $title,
                'network' => $p->network->value,
                'network_label' => $p->network->label(),
                'channel' => $p->channel?->displayLabel(),
                'trigger' => $p->trigger->label(),
                'status' => $p->status->value,
                'copy' => $p->copy,
                'image_url' => $p->image_url,
                'link' => $p->link,
                'permalink' => $p->permalink,
                'error' => $p->error,
                'snapshot' => $p->snapshot,
                'scheduled_for' => $p->scheduled_for?->toIso8601String(),
                'sent_at' => $p->sent_at?->toIso8601String(),
                'created_at' => $p->created_at->toIso8601String(),
            ];
        })->values()->all();
    }

    /** @return array{queued: int, sent: int, failed: int, sent_7d: int} */
    public function counts(string $siteId): array
    {
        $base = SocialPost::withoutGlobalScope(SiteScope::class)->where('site_id', $siteId);

        return [
            'queued' => (clone $base)->where('status', SocialPostStatus::Queued->value)->count(),
            'sent' => (clone $base)->where('status', SocialPostStatus::Sent->value)->count(),
            'failed' => (clone $base)->where('status', SocialPostStatus::Failed->value)->count(),
            'sent_7d' => (clone $base)->where('status', SocialPostStatus::Sent->value)->where('sent_at', '>=', Carbon::now()->subDays(7))->count(),
        ];
    }

    /**
     * What the operator can share by hand: the latest published jobs and posts, with a flag for the
     * question posts that carry a GBP Q&A pair.
     *
     * @return array{jobs: array<string, string>, posts: array<string, string>}
     */
    public function shareable(string $siteId, int $limit = 40): array
    {
        $jobs = Job::withoutGlobalScope(SiteScope::class)
            ->where('site_id', $siteId)->where('status', JobStatus::Published->value)
            ->orderByDesc('updated_at')->limit($limit)->get()
            ->mapWithKeys(fn (Job $j): array => [(string) $j->id => (string) ($j->post_title ?: 'Job '.$j->publicSlug())])->all();
        $posts = Content::withoutGlobalScope(SiteScope::class)
            ->where('site_id', $siteId)->where('kind', ContentKind::Post->value)->where('status', ContentStatus::Published->value)
            ->orderByDesc('published_at')->limit($limit)->get()
            ->mapWithKeys(fn (Content $c): array => [(string) $c->id => (string) $c->title])->all();

        return ['jobs' => $jobs, 'posts' => $posts];
    }

    /**
     * The GBP Q&A pairs for the site's question posts — copied by hand onto the Business Profile.
     *
     * @return list<array{content_id: string, title: string, question: string, answer: string}>
     */
    public function gbpQa(string $siteId, int $limit = 20): array
    {
        $out = [];
        $posts = Content::withoutGlobalScope(SiteScope::class)
            ->where('site_id', $siteId)->where('kind', ContentKind::Post->value)->where('status', ContentStatus::Published->value)
            ->whereNotNull('meta->question_refresh->answer')
            ->with('site')
            ->orderByDesc('updated_at')->limit($limit)->get();
        foreach ($posts as $post) {
            $qa = $this->composer->gbpQa($post);
            if ($qa !== null) {
                $out[] = ['content_id' => (string) $post->id, 'title' => (string) $post->title, ...$qa];
            }
        }

        return $out;
    }
}
