<?php

namespace App\Social;

use App\Enums\ContentKind;
use App\Enums\ContentStatus;
use App\Enums\SocialNetwork;
use App\Enums\SocialPostStatus;
use App\Enums\SocialSuggestionStatus;
use App\Enums\SocialTrigger;
use App\Jobs\SendSocialPost;
use App\Models\Content;
use App\Models\Location;
use App\Models\Scopes\SiteScope;
use App\Models\Site;
use App\Models\SocialChannel;
use App\Models\SocialPost;
use App\Models\SocialSuggestion;
use Illuminate\Support\Carbon;

/**
 * Lays re-shares out over a window (30 days by default): one published post per cadence slot, the ticked
 * networks, the ticked offices, a spotlight town per GBP office. `plan()` is read-only and returns the table
 * the operator reviews (every copy editable); `commit()` queues it — each item is an approved `Planned`
 * suggestion whose shares carry the chosen date, so the channel cap, the send job and the "Went out" record
 * all work as for any other share. `upcoming()` / `reschedule()` / `cancel()` run the calendar strip.
 * Job-capture shares keep flowing beside it; a planned share on a day the office already posts slides.
 */
final class SharePlanner
{
    public const CADENCES = ['daily' => 'Every day', 'alternate' => 'Every other day', 'mwf' => 'Mon · Wed · Fri', 'weekly' => 'Weekly'];

    public function __construct(
        private readonly SocialSharer $sharer,
        private readonly SocialComposer $composer,
        private readonly SpotlightTowns $spotlight,
        private readonly SuggestionBoard $board,
    ) {}

    /**
     * @param  array{days?: int, cadence?: string, start?: string|null, networks?: list<string>, location_ids?: list<string>|null, gbp_link?: string, post_ids?: list<string>|null, hour?: int|null}  $options
     * @return array{items: list<array<string, mixed>>, slots: int, unfilled: int, posts_available: int}
     */
    public function plan(Site $site, array $options = []): array
    {
        $days = max(1, min(90, (int) ($options['days'] ?? 30)));
        $cadence = (string) ($options['cadence'] ?? 'alternate');
        $hour = (int) ($options['hour'] ?? config('launchpad.social.slot_hour', 14));
        $start = isset($options['start']) && is_string($options['start']) && $options['start'] !== '' ? Carbon::parse($options['start'])->startOfDay() : Carbon::now()->addDay()->startOfDay();
        $networks = array_values(array_filter(array_map(fn (string $n): ?SocialNetwork => SocialNetwork::tryFrom($n), $options['networks'] ?? array_map(fn (SocialNetwork $n): string => $n->value, $this->sharer->networksFor((string) $site->id)))));
        $gbpLink = (string) ($options['gbp_link'] ?? 'town');

        $offices = $this->board->gbpOffices((string) $site->id);
        $wanted = $options['location_ids'] ?? null;
        if (is_array($wanted)) {
            $offices = array_values(array_filter($offices, fn (array $o): bool => in_array($o['id'], $wanted, true)));
        }
        $gbpChannels = SocialChannel::withoutGlobalScope(SiteScope::class)
            ->where('site_id', $site->id)->where('network', SocialNetwork::Gbp->value)->takesShares()->whereNotNull('location_id')
            ->get()->keyBy(fn (SocialChannel $c): string => (string) $c->location_id);
        $locations = Location::withoutGlobalScopes()->whereIn('id', array_column($offices, 'id'))->get()->keyBy('id');

        $slots = $this->slots($start, $days, $cadence, $hour);
        $posts = $this->posts($site, $options['post_ids'] ?? null, count($slots));

        $items = [];
        $usedTowns = [];
        foreach ($slots as $i => $when) {
            $post = $posts[$i] ?? null;
            if ($post === null) {
                break;
            }
            $item = [
                'index' => $i,
                'date' => $when->toDateString(),
                'at' => $when->toIso8601String(),
                'content_id' => (string) $post->id,
                'title' => (string) $post->title,
                'networks' => array_map(fn (SocialNetwork $n): string => $n->value, $networks),
                'copy' => [],
                'offices' => [],
            ];
            foreach ($networks as $network) {
                if ($network->perLocation()) {
                    continue;
                }
                $item['copy'][$network->value] = $this->composer->post($post, $network, SocialTrigger::Planned)->copy;
            }
            if (in_array(SocialNetwork::Gbp, $networks, true)) {
                foreach ($offices as $office) {
                    $channel = $gbpChannels->get($office['id']);
                    $location = $locations->get($office['id']);
                    if ($channel === null || $location === null) {
                        continue;
                    }
                    $spot = $this->spotlight->pick($site, $location, $channel, $days, seed: null);
                    // Within one plan, spread the towns too: re-draw once if this office already used it.
                    if ($spot !== null && isset($usedTowns[$channel->id][$spot['area_id']])) {
                        $again = $this->spotlight->pick($site, $location, $channel, $days);
                        $spot = $again ?? $spot;
                    }
                    if ($spot !== null) {
                        $usedTowns[(string) $channel->id][$spot['area_id']] = true;
                    }
                    $lead = $spot !== null ? self::lead($spot['town']) : null;
                    $link = $spot !== null && $gbpLink === 'town' ? $spot['page_url'] : null;
                    $copy = $this->composer->post($post, SocialNetwork::Gbp, SocialTrigger::Planned, $lead, $link);
                    $item['offices'][] = [
                        'location_id' => $office['id'],
                        'channel_id' => (string) $channel->id,
                        'town' => $office['town'],
                        'spotlight' => $spot,
                        'lead' => $lead,
                        'link_to' => $link,
                        'link' => $copy->link,
                        'copy' => $copy->copy,
                    ];
                }
            }
            $items[] = $item;
        }

        return ['items' => $items, 'slots' => count($slots), 'unfilled' => max(0, count($slots) - count($items)), 'posts_available' => count($posts)];
    }

    /**
     * Queue a reviewed plan. `$copy` carries the operator's edits: item index → ['facebook' => …, 'instagram' => …,
     * 'gbp:<channel id>' => …]; anything absent keeps the generated text.
     *
     * @param  list<array<string, mixed>>  $items  the plan's items as returned by plan() (edited or not)
     * @param  array<int|string, array<string, string>>  $copy
     * @return array{suggestions: int, shares: int}
     */
    public function commit(Site $site, array $items, array $copy = []): array
    {
        $made = 0;
        $shares = 0;
        foreach ($items as $item) {
            $post = Content::withoutGlobalScope(SiteScope::class)->with(['site', 'silo', 'renderJobs'])->where('site_id', $site->id)->find($item['content_id'] ?? null);
            if ($post === null) {
                continue;
            }
            $at = Carbon::parse((string) $item['at']);
            $networks = array_values(array_filter(array_map(fn (string $n): ?SocialNetwork => SocialNetwork::tryFrom($n), (array) ($item['networks'] ?? []))));
            $offices = (array) ($item['offices'] ?? []);
            $locationIds = array_values(array_map(fn (array $o): string => (string) $o['location_id'], $offices));
            if (in_array(SocialNetwork::Gbp, $networks, true) && $locationIds === []) {
                $networks = array_values(array_filter($networks, fn (SocialNetwork $n): bool => $n !== SocialNetwork::Gbp));
            }
            if ($networks === []) {
                continue;
            }
            $perChannel = [];
            $spotlights = [];
            foreach ($offices as $o) {
                $perChannel[(string) $o['channel_id']] = ['lead' => isset($o['lead']) ? (string) $o['lead'] : null, 'link' => isset($o['link_to']) && is_string($o['link_to']) && $o['link_to'] !== '' ? $o['link_to'] : null];
                if (is_array($o['spotlight'] ?? null)) {
                    $spotlights[(string) $o['channel_id']] = $o['spotlight'];
                }
            }
            $suggestion = SocialSuggestion::withoutGlobalScope(SiteScope::class)->create([
                'site_id' => $site->id,
                'content_id' => $post->id,
                'trigger' => SocialTrigger::Planned,
                'status' => SocialSuggestionStatus::Approved,
                'reason' => 'Planned for '.$at->toFormattedDateString(),
                'snapshot' => ['planned_for' => $at->toIso8601String(), 'spotlights' => $spotlights],
                'networks' => array_map(fn (SocialNetwork $n): string => $n->value, $networks),
                'location_ids' => in_array(SocialNetwork::Gbp, $networks, true) ? $locationIds : null,
                'decided_at' => Carbon::now(),
            ]);
            $rows = $this->sharer->sharePost($post, SocialTrigger::Planned, ['planned_for' => $at->toIso8601String()], $networks, $locationIds !== [] ? $locationIds : null, $suggestion, null, $at, $perChannel);
            $edits = $copy[(int) ($item['index'] ?? -1)] ?? [];
            foreach ($rows as $row) {
                $key = $row->network === SocialNetwork::Gbp ? 'gbp:'.$row->social_channel_id : $row->network->value;
                $attrs = [];
                if (isset($edits[$key]) && trim((string) $edits[$key]) !== '') {
                    $text = (string) $edits[$key];
                    $attrs['copy'] = $row->network === SocialNetwork::Gbp ? SocialComposer::withoutPhoneNumbers($text) : $text;
                }
                if ($row->network === SocialNetwork::Gbp && isset($spotlights[(string) $row->social_channel_id])) {
                    $attrs['snapshot'] = [...(is_array($row->snapshot) ? $row->snapshot : []), 'spotlight' => $spotlights[(string) $row->social_channel_id]];
                }
                if ($attrs !== []) {
                    $row->forceFill($attrs)->save();
                }
                $shares++;
            }
            $made++;
        }

        return ['suggestions' => $made, 'shares' => $shares];
    }

    /**
     * What is queued ahead, by day — every network, planned or not — for the calendar strip.
     *
     * @return list<array{date: string, posts: list<array<string, mixed>>}>
     */
    public function upcoming(string $siteId, int $days = 30): array
    {
        $rows = SocialPost::withoutGlobalScope(SiteScope::class)
            ->where('site_id', $siteId)->where('status', SocialPostStatus::Queued->value)
            ->where('scheduled_for', '>', Carbon::now())
            ->where('scheduled_for', '<=', Carbon::now()->addDays($days)->endOfDay())
            ->with(['channel.location'])
            ->orderBy('scheduled_for')->get();
        $titles = Content::withoutGlobalScope(SiteScope::class)
            ->whereIn('id', $rows->where('subject_type', SocialPost::SUBJECT_POST)->pluck('subject_id')->unique()->values()->all())
            ->pluck('title', 'id');
        $byDay = [];
        foreach ($rows as $row) {
            $day = $row->scheduled_for?->toDateString() ?? 'unscheduled';
            $officeName = $row->channel?->location?->name;
            $byDay[$day] ??= [];
            $byDay[$day][] = [
                'id' => (string) $row->id,
                'network' => $row->network->value,
                'network_label' => $row->network->label(),
                'channel' => $row->channel?->displayLabel(),
                'office' => is_string($officeName) && $officeName !== '' ? ChannelLabel::officeTown($officeName) : null,
                'subject_type' => $row->subject_type,
                'title' => $row->subject_type === SocialPost::SUBJECT_POST ? (string) ($titles->get($row->subject_id) ?? 'post') : 'job',
                'trigger' => $row->trigger->value,
                'trigger_label' => $row->trigger->label(),
                'copy' => $row->copy,
                'spotlight' => $row->snapshot['spotlight']['town'] ?? null,
                'at' => $row->scheduled_for?->toIso8601String(),
            ];
        }
        $out = [];
        foreach ($byDay as $date => $posts) {
            $out[] = ['date' => $date, 'posts' => $posts];
        }

        return $out;
    }

    /** Move a queued share to another day (its hour kept); the send job wakes at the new time. */
    public function reschedule(SocialPost $post, Carbon $to): bool
    {
        if ($post->status !== SocialPostStatus::Queued) {
            return false;
        }
        $hour = $post->scheduled_for?->hour ?? (int) config('launchpad.social.slot_hour', 14);
        $when = $to->copy()->startOfDay()->setHour($hour);
        if ($when->isPast()) {
            $when = Carbon::now()->addMinutes(2);
        }
        $post->forceFill(['scheduled_for' => $when])->save();
        SendSocialPost::dispatch((string) $post->id)->delay($when);

        return true;
    }

    /** Take a queued share off the calendar; nothing is sent. */
    public function cancel(SocialPost $post): bool
    {
        if ($post->status !== SocialPostStatus::Queued) {
            return false;
        }
        $post->forceFill(['status' => SocialPostStatus::Skipped, 'error' => 'cancelled on the board'])->save();

        return true;
    }

    /** Rewrite a queued share's text (GBP: phone numbers scrubbed as always). */
    public function rewrite(SocialPost $post, string $copy): bool
    {
        if ($post->status !== SocialPostStatus::Queued) {
            return false;
        }
        $copy = trim($copy);
        if ($copy === '') {
            return false;
        }
        $post->forceFill(['copy' => $post->network === SocialNetwork::Gbp ? SocialComposer::withoutPhoneNumbers($copy) : $copy])->save();

        return true;
    }

    /** The spotlight line: one of the configured templates, the town filled in. */
    public static function lead(string $town): string
    {
        $templates = (array) config('launchpad.social.spotlight.leads', ['Serving {town} homeowners.']);
        $templates = array_values(array_filter(array_map(fn (mixed $t): string => trim((string) $t), $templates), fn (string $t): bool => $t !== ''));
        $template = $templates === [] ? 'Serving {town} homeowners.' : $templates[crc32($town) % count($templates)];

        return str_replace('{town}', $town, $template);
    }

    /** @return list<Carbon> */
    private function slots(Carbon $start, int $days, string $cadence, int $hour): array
    {
        $out = [];
        $end = $start->copy()->addDays($days - 1)->endOfDay();
        for ($d = $start->copy(); $d->lessThanOrEqualTo($end); $d->addDay()) {
            $ok = match ($cadence) {
                'daily' => true,
                'alternate' => $start->diffInDays($d) % 2 === 0,
                'mwf' => in_array($d->dayOfWeek, [Carbon::MONDAY, Carbon::WEDNESDAY, Carbon::FRIDAY], true),
                'weekly' => $start->diffInDays($d) % 7 === 0,
                default => $start->diffInDays($d) % 2 === 0,
            };
            if ($ok) {
                $out[] = $d->copy()->setHour($hour)->setMinute(0)->setSecond(0);
            }
        }

        return $out;
    }

    /**
     * The posts in slot order: the picked ids as given, else published posts not shared inside the cooldown,
     * never-shared first, then longest since the last share; one post per slot, no repeats.
     *
     * @param  list<string>|null  $ids
     * @return list<Content>
     */
    private function posts(Site $site, ?array $ids, int $limit): array
    {
        $query = Content::withoutGlobalScope(SiteScope::class)
            ->where('site_id', $site->id)->where('kind', ContentKind::Post->value)->where('status', ContentStatus::Published->value)
            ->with(['site', 'silo', 'renderJobs']);
        if (is_array($ids) && $ids !== []) {
            $found = $query->whereIn('id', $ids)->get()->keyBy('id');

            return array_values(array_filter(array_map(fn (string $id): ?Content => $found->get($id), $ids)));
        }
        $posts = $query->get();
        $cooldown = Carbon::now()->subDays((int) config('launchpad.social.reshare_cooldown_days', 60));
        $lastShare = SocialPost::withoutGlobalScope(SiteScope::class)
            ->where('site_id', $site->id)->where('subject_type', SocialPost::SUBJECT_POST)
            ->whereIn('status', [SocialPostStatus::Queued->value, SocialPostStatus::Sent->value])
            ->selectRaw('subject_id, max(created_at) as last_at')->groupBy('subject_id')->pluck('last_at', 'subject_id');

        return $posts
            ->filter(fn (Content $p): bool => ! $lastShare->has((string) $p->id) || Carbon::parse((string) $lastShare->get((string) $p->id))->lt($cooldown))
            ->sortBy(fn (Content $p): string => ($lastShare->has((string) $p->id) ? '1'.(string) $lastShare->get((string) $p->id) : '0').'|'.($p->published_at?->toDateTimeString() ?? ''))
            ->take($limit)->values()->all();
    }
}
