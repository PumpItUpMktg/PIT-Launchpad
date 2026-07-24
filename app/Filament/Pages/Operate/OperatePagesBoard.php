<?php

namespace App\Filament\Pages\Operate;

use App\Build\PlanSync;
use App\ContentEngine\Drafting\GroundingReadiness;
use App\ContentEngine\Review\ReviewActions;
use App\Enums\ContentKind;
use App\Jobs\GeneratePage;
use App\Models\BuildPage;
use App\Models\Content;
use App\Models\Scopes\SiteScope;
use App\Models\Site;
use App\Operate\PagesBoard;
use App\Publishing\DeleteFromWordpress;
use Filament\Notifications\Notification;
use Illuminate\Support\Facades\Auth;

/**
 * Base for the three Operate PAGES boards (Core / Service / Location) — the full lifecycle of one
 * page family on one surface: the work lane on top (generate → review → publish, morphing
 * primary), the live cards beneath (tracking + repush/regenerate/take-down). Actions mirror
 * Grow's and the Live boards' proven paths verbatim — nothing bespoke; membership between the two
 * lanes is state-driven. Site-scoped with the shared working-site session.
 *
 * @property-read array<string, mixed> $board
 * @property-read array<string, string> $siteOptions
 * @property-read array<string, bool> $sources
 */
abstract class OperatePagesBoard extends OperatePage
{
    public ?string $siteId = null;

    /** The content id whose inline reject-reason input is open (null = none). */
    public ?string $rejecting = null;

    public string $rejectReason = '';

    /** Which PagesBoard family this page renders. */
    abstract protected function family(): string;

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

    /**
     * The month-3 path: a client adds a service line or a location mid-contract — Sync plan
     * reconciles the manifest with the current source records and the new pages appear in the
     * work lane as "not generated" with a Generate action. Idempotent (existing pages untouched).
     */
    public function syncPlan(): void
    {
        $site = $this->getSite();
        if ($site === null) {
            return;
        }

        $added = app(PlanSync::class)->sync($site);

        Notification::make()
            ->{$added > 0 ? 'success' : 'info'}()
            ->title($added > 0 ? "{$added} new page(s) added to the plan" : 'Plan already up to date')
            ->body($added > 0 ? 'They appear in the work lane below, ready to Generate.' : 'Every service, location, and standard page already has a planned row.')
            ->send();
    }

    /** Switch the working site (session-persisted, shared with Grow/Live/Blog). */
    public function setSite(string $siteId): void
    {
        if (Site::query()->whereKey($siteId)->exists()) {
            session(['guided_site_id' => $siteId]);
            $this->siteId = $siteId;
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

    /** @return array<string, mixed> */
    public function getBoardProperty(): array
    {
        $site = $this->getSite();

        return $site === null
            ? ['work' => [], 'live' => []]
            : app(PagesBoard::class)->{$this->family()}($site);
    }

    /** @return array<string, bool> */
    public function getSourcesProperty(): array
    {
        $site = $this->getSite();

        return $site === null
            ? ['serp' => false, 'gsc' => false, 'ga' => false]
            : app(PagesBoard::class)->sources($site);
    }

    // ── Work-lane actions (Grow's proven paths, verbatim) ───────────────────

    public function generate(string $contentId): void
    {
        $content = $this->ownedPage($contentId);
        if ($content === null) {
            return;
        }

        // Honest gate: never queue a page that can't ground — it would only produce an empty draft.
        if (! app(GroundingReadiness::class)->ready($content)) {
            Notification::make()->warning()->title('Not ready yet')
                ->body('This page isn\'t ready to write yet — its details are still coming together.')->send();

            return;
        }

        GeneratePage::enqueue($content, actorId: Auth::id());

        Notification::make()->success()
            ->title('Queued — generating on the worker')
            ->body("'{$content->title}' is being drafted; it will appear ready for review shortly.")
            ->send();
    }

    public function approve(string $contentId): void
    {
        $content = $this->ownedPage($contentId);
        if ($content === null) {
            return;
        }

        $result = app(ReviewActions::class)->approve($content, Auth::id());
        if ($result->isBlocked()) {
            Notification::make()->danger()->title('Cannot approve')->body((string) $result->blockedReason)->send();

            return;
        }

        $notification = Notification::make()->success()->title('Approved — ready to publish');
        if ($result->warnings !== []) {
            $notification->body(implode(' ', $result->warnings));
        }
        $notification->send();
    }

    public function publish(string $contentId): void
    {
        $content = $this->ownedPage($contentId);
        if ($content === null) {
            return;
        }

        $result = app(ReviewActions::class)->publish($content, Auth::id());
        if ($result->isBlocked()) {
            Notification::make()->danger()->title('Cannot publish')->body($result->blockedReason)->send();

            return;
        }

        Notification::make()->success()->title('Publishing — composing and pushing to WordPress')->send();
    }

    /** Repush is publish on an already-live card — same idempotent path, same URL. */
    public function repush(string $contentId): void
    {
        $this->publish($contentId);
    }

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
        Notification::make()->success()->title('Regenerating')
            ->body("'{$content->title}' is being re-drafted; review it in the work lane above.")->send();
    }

    public function lock(string $contentId): void
    {
        $content = $this->ownedPage($contentId);
        if ($content === null) {
            return;
        }

        app(ReviewActions::class)->lock($content);
        Notification::make()->success()->title('Locked')
            ->body('Future publishes won\'t overwrite this page — unlock it to resume automatic updates.')->send();
    }

    public function startReject(string $contentId): void
    {
        $this->rejecting = $contentId;
        $this->rejectReason = '';
    }

    public function reject(string $contentId): void
    {
        $content = $this->ownedPage($contentId);
        if ($content === null) {
            return;
        }

        app(ReviewActions::class)->reject($content, trim($this->rejectReason) !== '' ? trim($this->rejectReason) : 'Rejected from the pages board');
        $this->rejecting = null;
        $this->rejectReason = '';
        Notification::make()->success()->title('Rejected — it can be regenerated.')->send();
    }

    // ── Header-menu curation (nav_featured + nav_order) ─────────────────────

    /**
     * Current header-menu state for every page on the site, keyed by content id — so each card can
     * render its own checkbox + order without threading the flags through the read models.
     *
     * @return array<string, array{featured: bool, order: int|null}>
     */
    public function getNavStateProperty(): array
    {
        if ($this->siteId === null) {
            return [];
        }

        return Content::withoutGlobalScope(SiteScope::class)
            ->where('site_id', $this->siteId)
            ->where('kind', ContentKind::Page->value)
            ->get(['id', 'nav_featured', 'nav_order'])
            ->mapWithKeys(fn (Content $c): array => [
                (string) $c->id => ['featured' => (bool) $c->nav_featured, 'order' => $c->nav_order],
            ])
            ->all();
    }

    /** Toggle whether a page appears in the site header's main menu. */
    public function toggleNavFeatured(string $contentId): void
    {
        $content = $this->ownedPage($contentId);
        if ($content === null) {
            return;
        }

        $content->forceFill(['nav_featured' => ! $content->nav_featured])->save();

        Notification::make()->success()
            ->title($content->nav_featured ? 'Added to the header menu' : 'Removed from the header menu')
            ->body('Push it live with "Sync header & footer" on the Portfolio.')
            ->send();
    }

    /** Set a page's manual sort within the header menu (blank clears it to auto order). */
    public function setNavOrder(string $contentId, ?string $value): void
    {
        $content = $this->ownedPage($contentId);
        if ($content === null) {
            return;
        }

        $order = ($value === null || trim($value) === '') ? null : max(1, (int) $value);
        $content->forceFill(['nav_order' => $order])->save();
    }

    // ── Live-card actions (the Live boards' proven paths) ───────────────────

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

        Notification::make()->success()->title('Taken down — back in the work lane')
            ->body("'{$content->title}' was removed from WordPress; republish it from this board on the same URL.")->send();
    }

    /**
     * Remove a page COMPLETELY — the opposite of Take down (which parks it as republishable). Deletes it
     * from WordPress if it's live, drops its plan entry so a later materialize doesn't recreate it, then
     * soft-deletes the Content so it leaves every board. Rebuilding the structure can bring it back.
     */
    public function removePage(string $contentId): void
    {
        $content = $this->ownedPage($contentId);
        if ($content === null) {
            return;
        }

        $wasLive = (int) ($content->wp_post_id ?? 0) > 0;
        if ($wasLive) {
            $result = app(DeleteFromWordpress::class)->delete($content);
            if (! $result['deleted'] && $result['on_wp']) {
                Notification::make()->danger()->title('Could not remove it')->body($result['message'])->send();

                return;
            }
        }

        BuildPage::withoutGlobalScope(SiteScope::class)
            ->where('site_id', $content->site_id)
            ->where('content_id', $content->id)
            ->delete();

        $title = (string) $content->title;
        $content->delete();

        Notification::make()->success()->title('Removed')
            ->body("'{$title}' was deleted from the plan".($wasLive ? ' and WordPress' : '').'. Rebuilding the structure can bring it back.')->send();
    }

    protected function ownedPage(string $contentId): ?Content
    {
        return $this->siteId === null ? null : Content::withoutGlobalScope(SiteScope::class)
            ->where('site_id', $this->siteId)
            ->where('kind', ContentKind::Page->value)
            ->whereKey($contentId)
            ->first();
    }
}
