<?php

namespace App\Filament\Pages\Operate;

use App\Enums\SocialNetwork;
use App\Enums\SocialTrigger;
use App\Jobs\SyncSocialChannels;
use App\Models\Content;
use App\Models\Location;
use App\Models\Scopes\SiteScope;
use App\Models\SocialChannel;
use App\Models\SocialSuggestion;
use App\Operator\ActiveTenant;
use App\Social\ProfilePicker;
use App\Social\SuggestionBoard;
use Filament\Notifications\Notification;
use Illuminate\Support\Facades\Auth;
use Throwable;

/**
 * Operate → Shares: the re-share board. Jobs share on their own and never appear here. A card per
 * suggested post — why it is proposed, the copy as it will post, the platforms pre-ticked, the GBP
 * locations resolved from the post — Approve posts it, Skip dismisses it. Below, what went out and why
 * (approved and the rain rule's automatic shares). Channels are read from the posting provider; the
 * only control is Sync now. Which provider profiles the tenant posts through, and tying an unplaced GBP
 * channel to an office, are Super Admin matters ({@see canManageChannels}); a Site Admin sees the board
 * and decides the re-shares.
 */
class OperateShares extends OperatePage
{
    protected static ?string $slug = 'operate/shares';

    protected static ?string $navigationLabel = 'Shares';

    protected static ?int $navigationSort = 10;

    protected string $view = 'filament.operate.shares';

    public ?string $siteId = null;

    /** @var array<string, array<string, bool>> suggestion id → network → ticked */
    public array $picks = [];

    /** @var array<string, array<string, bool>> suggestion id → office (location id) → ticked, for the GBP share */
    public array $offices = [];

    public ?string $pickPostId = null;

    public function mount(): void
    {
        $this->siteId = app(ActiveTenant::class)->id();
    }

    /** @return list<array<string, mixed>> */
    public function getOpenProperty(): array
    {
        if ($this->siteId === null) {
            return [];
        }
        $rows = app(SuggestionBoard::class)->open($this->siteId);
        foreach ($rows as $row) {
            if (! isset($this->picks[$row['id']])) {
                $this->picks[$row['id']] = array_fill_keys($row['networks'], true);
            }
            if (! isset($this->offices[$row['id']])) {
                $ticks = [];
                foreach ($row['gbp_offices'] as $office) {
                    $ticks[$office['id']] = (bool) $office['routed'];
                }
                $this->offices[$row['id']] = $ticks;
            }
        }

        return $rows;
    }

    /** @return list<array<string, mixed>> */
    public function getDecidedProperty(): array
    {
        return $this->siteId === null ? [] : app(SuggestionBoard::class)->decided($this->siteId);
    }

    /** @return list<array<string, mixed>> */
    public function getChannelsProperty(): array
    {
        return $this->siteId === null ? [] : app(SuggestionBoard::class)->channels($this->siteId);
    }

    /** Super Admin only: the posting profiles a tenant uses and the office a GBP channel is tied to. */
    public function getCanManageChannelsProperty(): bool
    {
        return Auth::user()?->isSuperAdmin() ?? false;
    }

    /**
     * The tenant's posting-provider profiles with what is connected on each (Super Admin only).
     *
     * @return array{profiles: list<array{name: string, summary: string, found: bool}>, error: string|null, warm: bool}
     */
    public function getProfilesProperty(): array
    {
        if (! $this->getCanManageChannelsProperty()) {
            return ['profiles' => [], 'error' => null, 'warm' => true];
        }
        $site = app(ActiveTenant::class)->site();
        if ($site === null) {
            return ['profiles' => [], 'error' => null, 'warm' => true];
        }
        try {
            return app(ProfilePicker::class)->forShares($site);
        } catch (Throwable $e) {
            // Never a 500 from this line: the fault is reported and printed where a Super Admin reads it.
            report($e);

            return ['profiles' => [], 'error' => get_class($e).': '.$e->getMessage(), 'warm' => false];
        }
    }

    /** @return array<string, string> */
    public function getPickableProperty(): array
    {
        return $this->siteId === null ? [] : app(SuggestionBoard::class)->pickable($this->siteId);
    }

    public function approve(string $suggestionId): void
    {
        $suggestion = $this->suggestion($suggestionId);
        if ($suggestion === null) {
            return;
        }
        $networks = array_keys(array_filter($this->picks[$suggestionId] ?? []));
        if ($networks === []) {
            Notification::make()->warning()->title('Tick at least one platform')->send();

            return;
        }
        // The GBP share goes to the ticked offices only; GBP ticked with no office is a slip, not a request.
        $officeIds = null;
        if (in_array(SocialNetwork::Gbp->value, $networks, true) && isset($this->offices[$suggestionId])) {
            $officeIds = array_values(array_map('strval', array_keys(array_filter($this->offices[$suggestionId]))));
            if ($officeIds === []) {
                Notification::make()->warning()->title('Tick at least one office for the GBP share, or untick GBP')->send();

                return;
            }
        }
        $rows = app(SuggestionBoard::class)->approve($suggestion, $networks, $officeIds);
        unset($this->picks[$suggestionId], $this->offices[$suggestionId]);
        if ($rows === []) {
            Notification::make()->warning()->title('Nothing queued')->body('No enabled channel takes this share on the ticked platforms.')->send();

            return;
        }
        Notification::make()->success()->title(sprintf('Queued %d share(s)', count($rows)))->send();
    }

    public function skip(string $suggestionId): void
    {
        $suggestion = $this->suggestion($suggestionId);
        if ($suggestion !== null) {
            app(SuggestionBoard::class)->skip($suggestion);
            unset($this->picks[$suggestionId], $this->offices[$suggestionId]);
        }
    }

    /** The operator's own pick: a published post onto the board as a suggestion. */
    public function suggestPost(): void
    {
        if ($this->siteId === null || $this->pickPostId === null || $this->pickPostId === '') {
            return;
        }
        $post = Content::withoutGlobalScope(SiteScope::class)->where('site_id', $this->siteId)->with('site')->find($this->pickPostId);
        $this->pickPostId = null;
        if ($post === null) {
            return;
        }
        $made = app(SuggestionBoard::class)->suggest($post, SocialTrigger::Manual, 'Picked by hand');
        if ($made === null) {
            Notification::make()->warning()->title('Not added')->body('This post already has an open suggestion, or the site has no channels yet.')->send();

            return;
        }
        Notification::make()->success()->title('Added to the board')->send();
    }

    /** The site's offices, for the assign-to-office control on an unmatched GBP channel. @return array<string, string> id → name */
    public function getLocationOptionsProperty(): array
    {
        if ($this->siteId === null) {
            return [];
        }

        return Location::withoutGlobalScope(SiteScope::class)
            ->where('site_id', $this->siteId)->whereNull('merged_into_id')
            ->orderBy('name')->get()
            ->mapWithKeys(fn (Location $l): array => [(string) $l->id => (string) $l->name])->all();
    }

    /**
     * Tie a GBP channel the sync could not place to an office by hand (or free it again with no office).
     * The assignment is locked, so later syncs keep it; the listing's own name in Google stays untouched.
     */
    public function assignChannel(string $channelId, ?string $locationId): void
    {
        if ($this->siteId === null || ! $this->getCanManageChannelsProperty()) {
            return;
        }
        $channel = SocialChannel::withoutGlobalScope(SiteScope::class)->where('site_id', $this->siteId)->find($channelId);
        if ($channel === null || ! $channel->network->perLocation()) {
            return;
        }
        $locationId = trim((string) $locationId);
        $location = $locationId === '' ? null : Location::withoutGlobalScope(SiteScope::class)->where('site_id', $this->siteId)->find($locationId);
        $channel->forceFill(['location_id' => $location?->id, 'location_locked' => $location !== null])->save();
        Notification::make()->success()
            ->title($location !== null ? "{$channel->displayLabel()} → {$location->name}" : "{$channel->displayLabel()} unassigned")
            ->body($location !== null ? 'This Business Profile now receives the shares for jobs in that office\'s towns. The sync keeps your assignment.' : 'It receives no shares until it is tied to an office.')
            ->send();
    }

    /** The state of the queued sync for this tenant (idle / queued / running / done / failed), for the panel. @return array<string, mixed> */
    public function getSyncStateProperty(): array
    {
        if ($this->siteId === null) {
            return ['state' => 'idle', 'at' => null, 'result' => null, 'error' => null];
        }
        try {
            return SyncSocialChannels::status($this->siteId);
        } catch (Throwable $e) {
            // The board must render whatever the cache holds; the fault is shown, not thrown.
            report($e);

            return ['state' => 'failed', 'at' => null, 'result' => null, 'error' => 'sync state unreadable: '.get_class($e).': '.$e->getMessage()];
        }
    }

    /**
     * Set a channel aside (another brand's listing on the same Google account, a Page not to post to): it
     * receives nothing and sits under "Ignored" until restored. The sync keeps the flag; nothing is deleted.
     */
    public function ignoreChannel(string $channelId): void
    {
        $channel = $this->manageable($channelId);
        if ($channel === null || $channel->ignored()) {
            return;
        }
        $channel->forceFill(['ignored_at' => now()])->save();
        Notification::make()->success()->title("{$channel->displayLabel()} ignored")->body('It receives no shares. Restore it from the Ignored list any time.')->send();
    }

    public function restoreChannel(string $channelId): void
    {
        $channel = $this->manageable($channelId);
        if ($channel === null || ! $channel->ignored()) {
            return;
        }
        $channel->forceFill(['ignored_at' => null])->save();
        Notification::make()->success()->title("{$channel->displayLabel()} restored")->body($channel->unmatched() ? 'Tie it to an office before it can receive shares.' : 'It takes shares again.')->send();
    }

    /** A channel of the active tenant, for a Super Admin — null otherwise. */
    private function manageable(string $channelId): ?SocialChannel
    {
        if ($this->siteId === null || ! $this->getCanManageChannelsProperty()) {
            return null;
        }

        return SocialChannel::withoutGlobalScope(SiteScope::class)->where('site_id', $this->siteId)->find($channelId);
    }

    /** Queue the provider read on the worker — a sync reads every listing and outruns a web request. */
    public function syncChannels(): void
    {
        if ($this->siteId === null) {
            return;
        }
        $site = app(ActiveTenant::class)->site();
        if ($site === null) {
            return;
        }
        SyncSocialChannels::start($site);
        $state = SyncSocialChannels::status($this->siteId);
        if ($state['state'] === 'done' && is_array($state['result'])) {
            // The sync queue ran it at once.
            $r = $state['result'];
            Notification::make()->success()->title(sprintf('%d profile(s) synced', (int) ($r['synced'] ?? 0)))
                ->body(($r['unmatched'] ?? []) !== [] ? 'Unmatched GBP: '.implode(', ', (array) $r['unmatched']) : null)->send();

            return;
        }
        if ($state['state'] === 'failed') {
            Notification::make()->danger()->title('Sync failed')->body($state['error'])->send();

            return;
        }
        Notification::make()->success()->title('Sync queued')->body('The worker is reading the provider\'s listings — the channels refresh here as it finishes (a minute or two).')->send();
    }

    private function suggestion(string $id): ?SocialSuggestion
    {
        if ($this->siteId === null) {
            return null;
        }

        return SocialSuggestion::withoutGlobalScope(SiteScope::class)->where('site_id', $this->siteId)->find($id);
    }
}
