<?php

namespace App\Social\Rules;

use App\Enums\ContentKind;
use App\Enums\ContentStatus;
use App\Enums\SocialPostStatus;
use App\Models\Content;
use App\Models\Scopes\SiteScope;
use App\Models\Site;
use App\Models\SocialPost;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;

/**
 * The posts worth sharing when it rains: every published post whose title or body carries the storm
 * vocabulary (`launchpad.social.weather.terms`), unless the operator pinned it out (`meta.social.weather`
 * false) — a pinned-in post (`true`) is in regardless. The draw is weighted random by staleness: the
 * longer since a post was shared anywhere, the likelier it comes up; anything shared to the target
 * channels inside the weather cooldown is out. Two locations on the same storm day draw separately.
 */
final class RainPool
{
    /** @return Collection<int, Content> */
    public function pool(Site $site): Collection
    {
        $terms = array_map('strtolower', (array) config('launchpad.social.weather.terms', []));

        return Content::withoutGlobalScope(SiteScope::class)
            ->where('site_id', $site->id)->where('kind', ContentKind::Post->value)->where('status', ContentStatus::Published->value)
            ->with(['site', 'silo', 'renderJobs'])
            ->get()
            ->filter(function (Content $post) use ($terms): bool {
                $pin = $post->meta['social']['weather'] ?? null;
                if ($pin === false) {
                    return false;
                }
                if ($pin === true) {
                    return true;
                }
                $hay = strtolower((string) $post->title.' '.strip_tags((string) $post->body));
                foreach ($terms as $term) {
                    if ($term !== '' && str_contains($hay, $term)) {
                        return true;
                    }
                }

                return false;
            })->values();
    }

    /**
     * Draw one post for a set of channels: exclude anything shared to those channels inside the cooldown,
     * weight the rest by days since last shared anywhere (never-shared counts as the oldest), pick at random.
     *
     * @param  Collection<int, Content>  $pool
     * @param  list<string>  $channelIds
     * @return array{post: Content, weight: int, days_since: int|null}|null
     */
    public function draw(Collection $pool, array $channelIds, int $cooldownDays, ?int $seed = null): ?array
    {
        if ($pool->isEmpty()) {
            return null;
        }
        $ids = $pool->pluck('id')->all();
        $recent = SocialPost::withoutGlobalScope(SiteScope::class)
            ->where('subject_type', SocialPost::SUBJECT_POST)->whereIn('subject_id', $ids)
            ->whereIn('social_channel_id', $channelIds)
            ->whereIn('status', [SocialPostStatus::Queued->value, SocialPostStatus::Sent->value])
            ->where('created_at', '>=', Carbon::now()->subDays($cooldownDays))
            ->pluck('subject_id')->flip();
        $last = SocialPost::withoutGlobalScope(SiteScope::class)
            ->where('subject_type', SocialPost::SUBJECT_POST)->whereIn('subject_id', $ids)
            ->whereIn('status', [SocialPostStatus::Queued->value, SocialPostStatus::Sent->value])
            ->selectRaw('subject_id, MAX(created_at) AS last_at')->groupBy('subject_id')->pluck('last_at', 'subject_id');

        $candidates = [];
        $total = 0;
        foreach ($pool as $post) {
            if ($recent->has($post->id)) {
                continue;
            }
            $lastAt = $last->get($post->id);
            $days = $lastAt === null ? null : Carbon::parse((string) $lastAt)->diffInDays(Carbon::now());
            $weight = $days === null ? 365 : max(1, (int) $days);
            $candidates[] = ['post' => $post, 'weight' => $weight, 'days_since' => $days === null ? null : (int) $days];
            $total += $weight;
        }
        if ($candidates === []) {
            return null;
        }
        if ($seed !== null) {
            mt_srand($seed);
            $rng = mt_rand(0, $total - 1);
        } else {
            $rng = random_int(0, $total - 1);
        }
        $acc = 0;
        foreach ($candidates as $c) {
            $acc += $c['weight'];
            if ($rng < $acc) {
                return $c;
            }
        }

        return $candidates[array_key_last($candidates)];
    }
}
