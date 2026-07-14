<?php

namespace App\Guided;

use App\ContentEngine\Drafting\GroundingReadiness;
use App\ContentEngine\Review\ReviewActions;
use App\Jobs\GeneratePage;
use App\Models\Content;
use App\Models\Scopes\SiteScope;
use App\Models\Site;
use App\Publishing\DeleteFromWordpress;
use BackedEnum;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Illuminate\Support\Facades\Auth;

/**
 * Base for the LIVE boards (Locations / Services / Core pages) — published pages with their
 * tracking blocks. NOT a guided step: no mount gate; shares the guided flow's working-site session
 * so Grow → Live keeps the same tenant. Actions mirror Grow's proven paths (idempotent repush via
 * ReviewActions::publish, regenerate via the queued GeneratePage, take-down via
 * DeleteFromWordpress) — a page acted on here flows back to the Grow board by STATE, not by data
 * moves.
 *
 * @property-read array<string, string> $siteOptions
 */
abstract class LiveBoardPage extends Page
{
    protected static string|BackedEnum|null $navigationIcon = 'heroicon-o-chart-bar';

    protected static string|\UnitEnum|null $navigationGroup = 'Live';

    public ?string $siteId = null;

    /**
     * Operate relay: with the new Operate group on, the three Live boards re-register under it
     * (after Dashboard/Blog/Grow), and the old Live group empties out of the sidebar. Untouched
     * otherwise. Read-only re-grouping — no functional change.
     */
    public static function getNavigationGroup(): string|\UnitEnum|null
    {
        return config('launchpad.new_operate_enabled') ? 'Operate' : static::$navigationGroup;
    }

    public static function getNavigationSort(): ?int
    {
        return (config('launchpad.new_operate_enabled') ? 3 : 0) + (static::$navigationSort ?? 0);
    }

    public function mount(): void
    {
        $requested = request()->query('site');
        $candidate = is_string($requested) ? $requested : session('guided_site_id');

        $site = is_string($candidate) ? Site::query()->find($candidate) : null;
        $site ??= Site::query()->orderBy('brand_name')->first();

        if ($site !== null) {
            session(['guided_site_id' => $site->id]);
            $this->siteId = $site->id;
        }
    }

    public function getSite(): ?Site
    {
        return $this->siteId === null ? null : Site::query()->find($this->siteId);
    }

    /** @return array<string, string> */
    public function getSiteOptionsProperty(): array
    {
        return Site::query()->orderBy('brand_name')->pluck('brand_name', 'id')->all();
    }

    /** Switch the working site (session-persisted, shared with the guided flow). */
    public function setSite(string $siteId): void
    {
        if (Site::query()->whereKey($siteId)->exists()) {
            session(['guided_site_id' => $siteId]);
            $this->siteId = $siteId;
        }
    }

    /** Re-push the live page to WordPress — §2's idempotent compose-and-push (same URL, same post). */
    public function repush(string $contentId): void
    {
        $content = $this->ownedPage($contentId);
        if ($content === null) {
            return;
        }

        $result = app(ReviewActions::class)->publish($content, Auth::id());
        if ($result->isBlocked()) {
            Notification::make()->danger()->title('Cannot repush')->body((string) $result->blockedReason)->send();

            return;
        }

        Notification::make()->success()->title('Repushing')
            ->body("'{$content->title}' is being re-composed and pushed to the same URL.")->send();
    }

    /** Re-draft the page — it leaves this board (back to Grow) until re-approved and republished. */
    public function regenerate(string $contentId): void
    {
        $content = $this->ownedPage($contentId);
        if ($content === null) {
            return;
        }

        if (! app(GroundingReadiness::class)->ready($content)) {
            Notification::make()->warning()->title('Not ready yet')
                ->body('This page isn\'t ready to re-write yet — its details are still coming together.')->send();

            return;
        }

        GeneratePage::enqueue($content, actorId: Auth::id());

        Notification::make()->success()->title('Regenerating — moved back to Grow')
            ->body("'{$content->title}' is being re-drafted; review and republish it from the Grow board. Its tracking history is kept.")->send();
    }

    /** Remove the live WordPress post (slug freed; the plan row stays republishable on Grow). */
    public function takeDown(string $contentId): void
    {
        $content = $this->ownedPage($contentId);
        if ($content === null) {
            return;
        }

        $result = app(DeleteFromWordpress::class)->delete($content);
        if (! $result['deleted'] && $result['on_wp']) {
            Notification::make()->danger()->title('Could not take it down')->body($result['message'])->send();

            return;
        }

        Notification::make()->success()->title('Taken down — moved back to Grow')
            ->body("'{$content->title}' was removed from WordPress; re-publish it from the Grow board on the same URL.")->send();
    }

    protected function ownedPage(string $contentId): ?Content
    {
        $site = $this->getSite();
        if ($site === null) {
            return null;
        }

        return Content::withoutGlobalScope(SiteScope::class)
            ->where('site_id', $site->id)
            ->whereKey($contentId)
            ->first();
    }
}
