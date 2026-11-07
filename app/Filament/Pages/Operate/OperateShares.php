<?php

namespace App\Filament\Pages\Operate;

use App\Enums\SocialTrigger;
use App\Models\Content;
use App\Models\Scopes\SiteScope;
use App\Models\SocialSuggestion;
use App\Operator\ActiveTenant;
use App\Social\ChannelSync;
use App\Social\SuggestionBoard;
use Filament\Notifications\Notification;
use Throwable;

/**
 * Operate → Shares: the re-share board. Jobs share on their own and never appear here. A card per
 * suggested post — why it is proposed, the copy as it will post, the platforms pre-ticked, the GBP
 * locations resolved from the post — Approve posts it, Skip dismisses it. Below, what went out and why
 * (approved and the rain rule's automatic shares). Channels are read from the posting provider; the
 * only control is Sync now.
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
        $rows = app(SuggestionBoard::class)->approve($suggestion, $networks);
        unset($this->picks[$suggestionId]);
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
            unset($this->picks[$suggestionId]);
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

    public function syncChannels(): void
    {
        if ($this->siteId === null) {
            return;
        }
        $site = app(ActiveTenant::class)->site();
        if ($site === null) {
            return;
        }
        try {
            $r = app(ChannelSync::class)->sync($site);
        } catch (Throwable $e) {
            Notification::make()->danger()->title('Sync failed')->body($e->getMessage())->send();

            return;
        }
        Notification::make()->success()->title(sprintf('%d profile(s) synced', $r['synced']))
            ->body($r['unmatched'] !== [] ? 'Unmatched GBP: '.implode(', ', $r['unmatched']) : null)->send();
    }

    private function suggestion(string $id): ?SocialSuggestion
    {
        if ($this->siteId === null) {
            return null;
        }

        return SocialSuggestion::withoutGlobalScope(SiteScope::class)->where('site_id', $this->siteId)->find($id);
    }
}
