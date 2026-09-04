<?php

namespace App\Filament\Pages\Operate;

use App\Build\PlanSync;
use App\ContentEngine\Drafting\GroundingReadiness;
use App\ContentEngine\Review\ReviewActions;
use App\Enums\AuditAction;
use App\Enums\ContentKind;
use App\Enums\ContentStatus;
use App\Enums\MarketTier;
use App\Integrations\IndexNow\IndexNowSubmitter;
use App\Integrations\SearchConsole\SitemapSubmitter;
use App\Jobs\GeneratePage;
use App\Locations\CityKeywordTracker;
use App\Models\BuildPage;
use App\Models\Content;
use App\Models\Market;
use App\Models\Scopes\SiteScope;
use App\Models\Site;
use App\Operate\PagesBoard;
use App\Operate\QueueHealth;
use App\Operator\ActiveTenant;
use App\Publishing\DeleteFromWordpress;
use App\Publishing\Links\HubSpokeGuard;
use App\Publishing\PostPublisher;
use App\Security\Audit;
use Filament\Actions\Action;
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
 * @property-read array<string, bool> $sources
 */
abstract class OperatePagesBoard extends OperatePage
{
    public ?string $siteId = null;

    /** The content id whose inline reject-reason input is open (null = none). */
    public ?string $rejecting = null;

    public string $rejectReason = '';

    /** The content id whose ordering-guard interstitial is open (a hub/town with unpublished targets). */
    public ?string $confirmingPublish = null;

    /**
     * The unpublished targets naming the interstitial (its dead links) — {id, title, kind}.
     *
     * @var list<array{id: string, title: string, kind: string}>
     */
    public array $confirmBlockers = [];

    /** Which PagesBoard family this page renders. */
    abstract protected function family(): string;

    public function mount(): void
    {
        // The working tenant is the locked ActiveTenant (Portfolio / topbar switcher), shared with
        // Grow / Live / Blog — never a per-board site selection.
        $this->siteId = app(ActiveTenant::class)->id();
    }

    /** Ceiling on one inline-drain click so a huge backlog can't time out the web request. */
    private const DRAIN_BATCH = 25;

    /**
     * Background-worker health — publishing is async (Publish/Repush queue a job the worker runs). If
     * the worker is down, approved pages sit at "publishing" forever, which looks like a broken button;
     * surface it here with the inline-drain escape hatch instead. Carries the tenant brand for the
     * `launchpad:drain-publish` hint.
     *
     * @return array{pending: int, oldest_minutes: int, failed: int, processing: int, draining: bool, worker_down: bool, stalled: bool, brand: string, failures: list<array{job: string, reason: string, count: int, last: string, pages: list<string>}>}
     */
    public function getQueueHealthProperty(): array
    {
        $site = $this->getSite();
        $health = app(QueueHealth::class);
        $snapshot = $health->snapshot();
        $snapshot['brand'] = $site !== null ? (string) $site->brand_name : '';
        $snapshot['failures'] = $snapshot['failed'] > 0 ? $health->failures() : [];

        return $snapshot;
    }

    /**
     * Escape hatch when the worker is down: publish THIS site's in-flight pages + posts SYNCHRONOUSLY,
     * right now, via the same PostPublisher the `launchpad:drain-publish` command uses (WP-connection
     * gate + idempotent-by-ULID re-push still apply). Bounded per click so a huge backlog can't time out
     * the request — click again for more. A manual recovery tool, not a substitute for a running worker.
     */
    public function drainStuckPages(): void
    {
        $site = $this->getSite();
        if ($site === null) {
            return;
        }

        $inflight = Content::withoutGlobalScope(SiteScope::class)
            ->where('site_id', $site->id)
            ->whereIn('status', [
                ContentStatus::Approved->value,
                ContentStatus::Rendering->value,
                ContentStatus::Publishing->value,
            ])
            ->orderBy('updated_at')
            ->limit(self::DRAIN_BATCH)
            ->get();

        if ($inflight->isEmpty()) {
            Notification::make()->info()->title('Nothing to drain')
                ->body('No pages or posts are stuck waiting to publish for this site.')->send();

            return;
        }

        $publisher = app(PostPublisher::class);
        $published = 0;
        foreach ($inflight as $content) {
            if ($publisher->publish($content, Auth::id())->isPublished()) {
                $published++;
            }
        }

        $more = $inflight->count() === self::DRAIN_BATCH;
        Notification::make()
            ->{$published === $inflight->count() ? 'success' : 'warning'}()
            ->title("Published {$published} of {$inflight->count()} stuck item(s)")
            ->body($more
                ? 'More may remain — click again to continue draining.'
                : ($published < $inflight->count()
                    ? 'Some did not publish — check each item\'s status/error, and fix the worker (Horizon / queue:work) so this is automatic.'
                    : 'Fix the worker (Horizon / queue:work) so publishing is automatic again.'))
            ->send();
    }

    /** Clear the failed-jobs backlog — the banner's "Clear failed" button (Laravel's queue:flush). */
    public function clearFailedJobs(): void
    {
        $cleared = app(QueueHealth::class)->clearFailed();

        Notification::make()
            ->{$cleared > 0 ? 'success' : 'info'}()
            ->title($cleared > 0 ? "Cleared {$cleared} failed job(s)" : 'No failed jobs to clear')
            ->body($cleared > 0 ? 'The stalled-worker banner clears with them. Fix the underlying cause so they don\'t recur.' : '')
            ->send();
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

    public function getSite(): ?Site
    {
        return $this->siteId === null ? null : Site::query()->find($this->siteId);
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

    /**
     * Bulk-generate every page in this family's work lane that is ready to write — the one-click
     * repopulate after a Sync plan (or a cleared/rebuilt site). Only rows whose primary action is
     * Generate are eligible (already-drafted / in-flight pages are left alone), and each still passes
     * the same honest grounding gate as the single Generate; the not-yet-ready are skipped, not queued.
     */
    public function generateAllReady(): void
    {
        if ($this->getSite() === null) {
            return;
        }

        $queued = 0;
        $skipped = 0;
        foreach ($this->getBoardProperty()['work'] ?? [] as $row) {
            if (! in_array('generate', $row['actions'] ?? [], true)) {
                continue;
            }
            $content = $this->ownedPage((string) $row['id']);
            if ($content === null) {
                continue;
            }
            if (! app(GroundingReadiness::class)->ready($content)) {
                $skipped++;

                continue;
            }
            GeneratePage::enqueue($content, actorId: Auth::id());
            $queued++;
        }

        if ($queued === 0) {
            Notification::make()->info()
                ->title($skipped > 0 ? 'Nothing generated' : 'Nothing to generate')
                ->body($skipped > 0
                    ? "{$skipped} page(s) aren't ready to write yet — their details are still coming together."
                    : 'Every page here is already drafted or in flight.')
                ->send();

            return;
        }

        Notification::make()->success()
            ->title("Generating {$queued} page(s) on the worker")
            ->body(($skipped > 0 ? "{$skipped} not ready yet were skipped. " : '').'They\'ll appear ready for review as each finishes.')
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

        // Ordering guard (report fix 2): a hub whose spoke grid — or a town whose parent hub — points at
        // pages not live yet would publish into dead links. Hold for an explicit choice: push the missing
        // pages first, or override. Never publish into dead links silently.
        $blockers = app(HubSpokeGuard::class)->unpublishedTargets($content);
        if ($blockers !== []) {
            $this->confirmingPublish = $contentId;
            $this->confirmBlockers = $blockers;

            return;
        }

        $this->doPublish($content);
    }

    /** Push the interstitial's unpublished targets first, then publish the held page onto live links. */
    public function pushSpokesFirst(): void
    {
        $held = $this->confirmingPublish !== null ? $this->ownedPage($this->confirmingPublish) : null;
        if ($held === null) {
            $this->cancelPublish();

            return;
        }

        $pushed = 0;
        foreach ($this->confirmBlockers as $blocker) {
            $target = $this->ownedPage($blocker['id']);
            if ($target === null) {
                continue;
            }
            $result = app(ReviewActions::class)->publish($target, Auth::id());
            if (! $result->isBlocked()) {
                $pushed++;
            }
        }

        $this->doPublish($held);
        $this->cancelPublish();
        Notification::make()->success()->title("Pushing {$pushed} page(s) first, then this one")
            ->body('The linked pages publish first so this page\'s links resolve.')->send();
    }

    /** Publish the held page anyway — the operator's explicit override, written to the audit log. */
    public function publishAnyway(): void
    {
        $held = $this->confirmingPublish !== null ? $this->ownedPage($this->confirmingPublish) : null;
        if ($held === null) {
            $this->cancelPublish();

            return;
        }

        app(Audit::class)->log(AuditAction::DeadLinkOverride, $held, Auth::id(), [
            'unpublished_targets' => array_map(fn (array $b): string => $b['title'], $this->confirmBlockers),
        ]);
        $this->doPublish($held);
        $this->cancelPublish();
    }

    public function cancelPublish(): void
    {
        $this->confirmingPublish = null;
        $this->confirmBlockers = [];
    }

    /** The actual publish, past the ordering guard. */
    private function doPublish(Content $content): void
    {
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

    /**
     * @return array<int, Action>
     */
    protected function getHeaderActions(): array
    {
        return [$this->submitSitemapAction(), $this->pingIndexNowAction()];
    }

    /**
     * "Ping search engines (IndexNow)" — submits the site's published URLs to IndexNow so Bing, Yandex,
     * Seznam and Naver crawl them right away. Free, no per-request auth (Google doesn't participate —
     * the sitemap submit covers Google). Deploys the site's key to the companion plugin on first use.
     */
    protected function pingIndexNowAction(): Action
    {
        return Action::make('pingIndexNow')
            ->label('Ping search engines (IndexNow)')
            ->icon('heroicon-o-signal')
            ->color('gray')
            ->requiresConfirmation()
            ->modalHeading('Submit URLs to IndexNow')
            ->modalDescription('Tells Bing, Yandex, Seznam and Naver to crawl your published pages now. Free. Google doesn\'t use IndexNow (its sitemap submit covers Google). Requires the companion plugin updated so it can serve the verification key.')
            ->modalSubmitActionLabel('Ping now')
            ->action(fn () => $this->pingIndexNow());
    }

    private function pingIndexNow(): void
    {
        $site = $this->getSite();
        if ($site === null) {
            return;
        }

        $result = app(IndexNowSubmitter::class)->submitSite($site);

        if (! $result['ok']) {
            Notification::make()->warning()
                ->title('Could not submit to IndexNow')
                ->body((string) $result['reason'])
                ->send();

            return;
        }

        Notification::make()->success()
            ->title('Submitted to IndexNow')
            ->body(sprintf('%d URL(s) sent to Bing, Yandex, Seznam and Naver — they\'ll crawl shortly.', $result['submitted']))
            ->send();
    }

    /**
     * "Submit sitemap to Google" — pushes the site's sitemap (/sitemap.xml, served by the companion
     * plugin) to Search Console so Google discovers + indexes the pages. Free (no DataForSEO); uses the
     * shared Google grant. Reports the URL count Google took.
     */
    protected function submitSitemapAction(): Action
    {
        return Action::make('submitSitemap')
            ->label('Submit sitemap to Google')
            ->icon('heroicon-o-globe-alt')
            ->color('gray')
            ->requiresConfirmation()
            ->modalHeading('Submit sitemap to Google Search Console')
            ->modalDescription('This tells Google to crawl and index your published pages (from /sitemap.xml). Free — no DataForSEO credits. Requires Search Console connected for this site.')
            ->modalSubmitActionLabel('Submit to Google')
            ->action(fn () => $this->submitSitemap());
    }

    private function submitSitemap(): void
    {
        $site = $this->getSite();
        if ($site === null) {
            return;
        }

        $result = app(SitemapSubmitter::class)->submit($site);

        if (! $result['ok']) {
            $reason = (string) $result['reason'];
            $lower = strtolower($reason);
            $body = match (true) {
                $result['reason'] === 'not_connected' => 'Connect Search Console for this site first (Connections → Connect Google, then pick its GSC property).',
                $result['reason'] === 'no_domain' => 'This site has no domain URL set.',
                str_contains($lower, 'insufficient') || str_contains($lower, 'permission') || str_contains($lower, 'scope') => 'Google refused (insufficient authority). Check two things: (1) Reconnect Google (Connections → Reconnect Google) so the grant includes sitemap-write access — the earlier grant was read-only. (2) The connected email must be an Owner or Full user on this site\'s Search Console property; a "Restricted" user cannot submit sitemaps.',
                default => $reason,
            };

            Notification::make()->warning()->title('Could not submit sitemap')->body($body)->send();

            return;
        }

        Notification::make()->success()
            ->title('Sitemap submitted to Google')
            ->body(sprintf(
                'Google is crawling %s — it reports %d URL(s)%s. Indexing can take days; the "✓ In Google" badge lights a page once it appears in Search.',
                (string) $result['sitemap'],
                $result['submitted'],
                $result['pending'] ? ' (still processing)' : '',
            ))
            ->send();
    }

    /**
     * Mark this city page's Market priority (or back to coverage) from the card — the operator picks
     * which cities are worth paid §5 rank tracking. Promoting immediately assigns the city's
     * "{service} {city}" tracking keywords; demoting prunes them. No DataForSEO credits here — the pull
     * is the separate "Refresh rankings now". Only location pages (which carry a market) get the toggle.
     */
    public function toggleCityPriority(string $contentId): void
    {
        $content = $this->ownedPage($contentId);
        if ($content === null || $content->market_id === null) {
            return;
        }

        $market = Market::withoutGlobalScope(SiteScope::class)->find($content->market_id);
        if ($market === null) {
            return;
        }

        $promote = $market->tier !== MarketTier::Priority;
        $market->forceFill(['tier' => ($promote ? MarketTier::Priority : MarketTier::Coverage)->value])->save();

        // Reconcile city-keyword tracking for the whole site (creates on promote, prunes on demote).
        $site = $this->getSite();
        if ($site !== null) {
            app(CityKeywordTracker::class)->assign($site);
        }

        Notification::make()->success()
            ->title($promote ? "{$market->name} marked priority" : "{$market->name} set to coverage")
            ->body($promote
                ? 'City keywords assigned — pull positions with "Refresh rankings now" (uses DataForSEO credits).'
                : 'City-keyword tracking removed for this city.')
            ->send();
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
