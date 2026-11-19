<?php

namespace App\Social;

use App\Enums\ContentKind;
use App\Enums\ContentStatus;
use App\Enums\SocialNetwork;
use App\Enums\SocialPostStatus;
use App\Enums\SocialSuggestionStatus;
use App\Enums\SocialTrigger;
use App\Models\Content;
use App\Models\Location;
use App\Models\Scopes\SiteScope;
use App\Models\Site;
use App\Models\SocialChannel;
use App\Models\SocialPost;
use App\Models\SocialSuggestion;
use Illuminate\Support\Carbon;

/**
 * The re-share board. A rule (a question refresh landing, upward movement in Search Console, an operator's
 * pick) proposes a post; the operator ticks the platforms and approves, or skips. The rain rule sends on
 * its own and records an `auto` row here so the board shows what went and why. One open suggestion per
 * post; a post shared or decided inside the cooldown is not proposed again until a new reason fires.
 */
final class SuggestionBoard
{
    public function __construct(
        private readonly SocialSharer $sharer,
        private readonly PostLocationRouter $router,
        private readonly SocialComposer $composer,
    ) {}

    /**
     * Propose a post. Returns null when the post already has an open suggestion, or was shared / decided
     * inside the cooldown (a manual pick ignores the cooldown — the operator asked).
     *
     * @param  array<string, mixed>|null  $snapshot
     */
    public function suggest(Content $post, SocialTrigger $trigger, string $reason, ?array $snapshot = null): ?SocialSuggestion
    {
        if ($post->kind !== ContentKind::Post || $post->status !== ContentStatus::Published) {
            return null;
        }
        $open = SocialSuggestion::withoutGlobalScope(SiteScope::class)
            ->where('content_id', $post->id)->where('status', SocialSuggestionStatus::Open->value)->first();
        if ($open !== null) {
            return null;
        }
        if ($trigger !== SocialTrigger::Manual && $this->insideCooldown($post)) {
            return null;
        }
        $networks = $this->sharer->networksFor((string) $post->site_id);
        if ($networks === []) {
            return null;
        }

        return SocialSuggestion::withoutGlobalScope(SiteScope::class)->create([
            'site_id' => $post->site_id,
            'content_id' => $post->id,
            'trigger' => $trigger,
            'status' => SocialSuggestionStatus::Open,
            'reason' => mb_substr($reason, 0, 500),
            'snapshot' => $snapshot,
            'networks' => array_map(fn (SocialNetwork $n): string => $n->value, $networks),
            'location_ids' => $this->router->forPost($post),
        ]);
    }

    /**
     * Approve with the ticked platforms: the shares queue (GBP to the post's resolved locations), the
     * suggestion closes.
     *
     * @param  list<string>  $networks
     * @return list<SocialPost>
     */
    /**
     * @param  list<string>  $networks  the ticked network values
     * @param  list<string>|null  $locationIds  the ticked GBP offices; null = the suggestion's own routing
     */
    public function approve(SocialSuggestion $suggestion, array $networks, ?array $locationIds = null): array
    {
        if ($suggestion->status !== SocialSuggestionStatus::Open) {
            return [];
        }
        $post = Content::withoutGlobalScope(SiteScope::class)->with(['site', 'silo', 'renderJobs'])->find($suggestion->content_id);
        if ($post === null) {
            $suggestion->forceFill(['status' => SocialSuggestionStatus::Skipped, 'decided_at' => Carbon::now()])->save();

            return [];
        }
        $picked = array_values(array_filter(array_map(fn (string $n): ?SocialNetwork => SocialNetwork::tryFrom($n), $networks)));
        if ($picked === []) {
            return [];
        }
        $trigger = $suggestion->trigger === SocialTrigger::PostRefreshed ? SocialTrigger::Manual : $suggestion->trigger;
        $offices = $locationIds ?? $suggestion->location_ids;
        $rows = $this->sharer->sharePost($post, $trigger, $suggestion->snapshot, $picked, $offices, $suggestion);
        $suggestion->forceFill(['status' => SocialSuggestionStatus::Approved, 'networks' => array_map(fn (SocialNetwork $n): string => $n->value, $picked), 'location_ids' => $offices, 'decided_at' => Carbon::now()])->save();

        return $rows;
    }

    public function skip(SocialSuggestion $suggestion): void
    {
        if ($suggestion->status === SocialSuggestionStatus::Open) {
            $suggestion->forceFill(['status' => SocialSuggestionStatus::Skipped, 'decided_at' => Carbon::now()])->save();
        }
    }

    /**
     * The record of a rule that sent on its own (rain): an `auto` row the board shows with its snapshot.
     *
     * @param  array<string, mixed>|null  $snapshot
     * @param  list<SocialNetwork>  $networks
     * @param  list<string>|null  $locationIds
     */
    public function recordAuto(Content $post, SocialTrigger $trigger, string $reason, ?array $snapshot, array $networks, ?array $locationIds): SocialSuggestion
    {
        return SocialSuggestion::withoutGlobalScope(SiteScope::class)->create([
            'site_id' => $post->site_id,
            'content_id' => $post->id,
            'trigger' => $trigger,
            'status' => SocialSuggestionStatus::Auto,
            'reason' => mb_substr($reason, 0, 500),
            'snapshot' => $snapshot,
            'networks' => array_map(fn (SocialNetwork $n): string => $n->value, $networks),
            'location_ids' => $locationIds,
            'decided_at' => Carbon::now(),
        ]);
    }

    /** Shared, approved, or skipped inside the re-share cooldown. */
    public function insideCooldown(Content $post): bool
    {
        $since = Carbon::now()->subDays((int) config('launchpad.social.reshare_cooldown_days', 60));
        $shared = SocialPost::withoutGlobalScope(SiteScope::class)
            ->where('subject_type', SocialPost::SUBJECT_POST)->where('subject_id', $post->id)
            ->whereIn('status', [SocialPostStatus::Queued->value, SocialPostStatus::Sent->value])
            ->where('created_at', '>=', $since)->exists();
        if ($shared) {
            return true;
        }

        return SocialSuggestion::withoutGlobalScope(SiteScope::class)
            ->where('content_id', $post->id)
            ->whereIn('status', [SocialSuggestionStatus::Approved->value, SocialSuggestionStatus::Skipped->value])
            ->where('decided_at', '>=', $since)->exists();
    }

    // ---- read model -------------------------------------------------------------------------------

    /** @return list<array<string, mixed>> */
    public function open(string $siteId): array
    {
        return $this->rows(SocialSuggestion::withoutGlobalScope(SiteScope::class)
            ->where('site_id', $siteId)->where('status', SocialSuggestionStatus::Open->value)
            ->with(['content.site', 'content.renderJobs', 'content.silo'])
            ->orderByDesc('created_at')->get()->all());
    }

    /** @return list<array<string, mixed>> */
    public function decided(string $siteId, int $limit = 40): array
    {
        return $this->rows(SocialSuggestion::withoutGlobalScope(SiteScope::class)
            ->where('site_id', $siteId)->where('status', '!=', SocialSuggestionStatus::Open->value)
            ->with(['content.site', 'content.renderJobs', 'content.silo', 'posts.channel.location'])
            ->orderByDesc('decided_at')->limit($limit)->get()->all());
    }

    /**
     * @param  list<SocialSuggestion>  $suggestions
     * @return list<array<string, mixed>>
     */
    private function rows(array $suggestions): array
    {
        $out = [];
        $officesBySite = [];
        foreach ($suggestions as $s) {
            $post = $s->content;
            if ($post === null) {
                continue;
            }
            $locations = $s->location_ids === null ? null : Location::withoutGlobalScope(SiteScope::class)->whereIn('id', $s->location_ids)->pluck('name')->all();
            // The offices a GBP share can go to (one GBP channel that takes shares, tied to the office), each
            // pre-ticked when the post's routing names it (or every office when the post names no town).
            $officesBySite[(string) $s->site_id] ??= $this->gbpOffices((string) $s->site_id);
            $gbpOffices = array_map(fn (array $o): array => [...$o, 'routed' => $s->location_ids === null || in_array($o['id'], $s->location_ids, true)], $officesBySite[(string) $s->site_id]);
            $preview = $this->composer->post($post, SocialNetwork::Facebook, $s->trigger);
            $gbpPreview = $this->composer->post($post, SocialNetwork::Gbp, $s->trigger);
            $qa = $this->composer->gbpQa($post);
            $out[] = [
                'id' => (string) $s->id,
                'content_id' => (string) $post->id,
                'title' => (string) $post->title,
                'trigger' => $s->trigger->value,
                'trigger_label' => $s->trigger->label(),
                'status' => $s->status->value,
                'reason' => $s->reason,
                'snapshot' => $s->snapshot,
                'networks' => $s->networks,
                'gbp_locations' => $locations,
                'gbp_offices' => $gbpOffices,
                'image_url' => $preview->imageUrl,
                'copy' => $preview->copy,
                'gbp_copy' => $gbpPreview->copy,
                'hashtags' => $preview->hashtags,
                'link' => $preview->link,
                'qa' => $qa,
                'created_at' => $s->created_at->toIso8601String(),
                'decided_at' => $s->decided_at?->toIso8601String(),
                'posts' => $s->relationLoaded('posts') ? $s->posts->map(fn (SocialPost $p): array => [
                    'network' => $p->network->value,
                    'channel' => $p->channel?->displayLabel(),
                    'status' => $p->status->value,
                    'permalink' => $p->permalink,
                    'error' => $p->error,
                    'sent_at' => $p->sent_at?->toIso8601String(),
                ])->values()->all() : [],
            ];
        }

        return $out;
    }

    /**
     * The offices a GBP share can reach: each office with a GBP channel that takes shares, by town name.
     *
     * @return list<array{id: string, name: string, town: string}>
     */
    public function gbpOffices(string $siteId): array
    {
        $ids = SocialChannel::withoutGlobalScope(SiteScope::class)
            ->where('site_id', $siteId)->where('network', SocialNetwork::Gbp->value)->takesShares()
            ->whereNotNull('location_id')->pluck('location_id')->unique()->values()->all();
        if ($ids === []) {
            return [];
        }

        return Location::withoutGlobalScopes()->whereIn('id', $ids)->orderBy('name')->get(['id', 'name'])
            ->map(fn (Location $l): array => ['id' => (string) $l->id, 'name' => (string) $l->name, 'town' => ChannelLabel::officeTown((string) $l->name)])
            ->sortBy('town')->values()->all();
    }

    /** @return list<array<string, mixed>> the synced channels, for the board's read-only line */
    public function channels(string $siteId): array
    {
        $channels = SocialChannel::withoutGlobalScope(SiteScope::class)
            ->where('site_id', $siteId)
            ->orderBy('network')->orderBy('provider_name')->get();
        // The office each GBP channel routes to, read without any scope: the panel is where the operator
        // verifies the matching, so it must name the office whatever the request's tenant context is.
        $offices = Location::withoutGlobalScopes()
            ->whereIn('id', $channels->pluck('location_id')->filter()->unique()->values()->all())
            ->pluck('name', 'id');
        $brand = (string) (Site::withoutGlobalScopes()->whereKey($siteId)->value('brand_name') ?? '');

        $rows = $channels->map(function (SocialChannel $c) use ($offices, $brand): array {
            $office = $c->location_id !== null ? $offices->get((string) $c->location_id) : null;
            $office = is_string($office) && $office !== '' ? $office : null;
            $short = $c->network->perLocation()
                ? ChannelLabel::gbpPill((string) $c->provider_name, $brand, $office)
                : (string) ($c->provider_name !== null && $c->provider_name !== '' ? $c->provider_name : $c->displayLabel());

            return [
                'id' => (string) $c->id,
                'location_id' => $c->location_id !== null ? (string) $c->location_id : null,
                'locked' => (bool) $c->location_locked,
                'network' => $c->network->value,
                'network_label' => $c->network->label(),
                // "Google Business Profile · Sump Pump Gurus | Trooper → Trooper office": the listing as Google
                // names it, then the office it routes to; a Page / Instagram account shows its name alone.
                'label' => $c->network->perLocation()
                    ? $c->network->label().' · '.($c->provider_name ?? '').($office !== null ? ' → '.$office : '')
                    : $c->displayLabel(),
                // The board pill: brand initials + the office town ("SPG · Trooper"); Google's title kept
                // for another brand or when it disagrees with the office. The full title stays in `label`.
                'short' => $short,
                'provider_name' => $c->provider_name,
                'address' => $c->provider_address,
                'location' => $office,
                'office_town' => $office !== null ? ChannelLabel::officeTown($office) : null,
                'enabled' => $c->enabled,
                'unmatched' => $c->unmatched(),
                'ignored' => $c->ignored(),
                'ignored_at' => $c->ignored_at?->toIso8601String(),
                'synced_at' => $c->synced_at?->toIso8601String(),
            ];
        });

        // Pages first, then GBP by the town it routes to (unmatched last), so the dense list reads as a map.
        return $rows->sortBy(fn (array $r): string => ($r['network'] === SocialNetwork::Gbp->value ? '1' : '0').'|'.($r['office_town'] !== null ? '0'.mb_strtolower($r['office_town']) : '1'.mb_strtolower((string) $r['provider_name'])))
            ->values()->all();
    }

    /** @return array<string, string> published posts the operator can pick for a manual suggestion */
    public function pickable(string $siteId, int $limit = 60): array
    {
        return Content::withoutGlobalScope(SiteScope::class)
            ->where('site_id', $siteId)->where('kind', ContentKind::Post->value)->where('status', ContentStatus::Published->value)
            ->orderByDesc('published_at')->limit($limit)->get()
            ->mapWithKeys(fn (Content $c): array => [(string) $c->id => (string) $c->title])->all();
    }
}
