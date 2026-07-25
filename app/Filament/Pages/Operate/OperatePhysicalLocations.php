<?php

namespace App\Filament\Pages\Operate;

use App\Build\Permalinks;
use App\ContentEngine\Drafting\GroundingReadiness;
use App\ContentEngine\Review\ReviewActions;
use App\Enums\PageType;
use App\Enums\RedirectSource;
use App\Filament\Pages\Gathering\BusinessStep;
use App\Filament\Pages\Gathering\LocationsStep;
use App\Integrations\Wordpress\WordpressClientFactory;
use App\Jobs\GeneratePage;
use App\Locations\LocationLandingFactory;
use App\Models\Content;
use App\Models\Location;
use App\Models\Redirect;
use App\Models\Scopes\SiteScope;
use App\Models\Site;
use App\Operate\PhysicalLocations;
use App\Publishing\DeleteFromWordpress;
use Filament\Notifications\Notification;
use Illuminate\Support\Facades\Auth;
use Throwable;

/**
 * Operate · Physical locations — one card per base location with the areas it serves. Surfaces
 * the two territory truths at a glance: OVERLAP between locations (flagged per town, naming the
 * other location — the goal state is zero) and the home-county SOFT RULE (a location should
 * serve the county it sits in and its towns — advisory, never enforced). Territory is edited in
 * the Service area workspace; this is the operator's display + audit surface.
 *
 * @property-read array{summary: array<string, int>, cards: list<array<string, mixed>>} $board
 * @property-read array<string, string> $siteOptions
 */
class OperatePhysicalLocations extends OperatePage
{
    protected static ?string $slug = 'operate/locations';

    protected static ?string $navigationLabel = 'Locations';

    protected static ?int $navigationSort = 6;

    protected string $view = 'filament.operate.physical-locations';

    public ?string $siteId = null;

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

    /** Switch the working site (session-persisted, shared with the rest of Operate). */
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

    /**
     * @return array{summary: array<string, int>, cards: list<array<string, mixed>>}
     */
    public function getBoardProperty(): array
    {
        $site = $this->getSite();

        return $site === null
            ? ['summary' => ['locations' => 0, 'towns_covered' => 0, 'towns_selected' => 0, 'overlaps' => 0], 'cards' => []]
            : app(PhysicalLocations::class)->build($site);
    }

    // ── Per-location page lifecycle (targets the location's landing/hub page) ──

    /**
     * Generate the location's landing page — find-or-creates the ONE page pinned to this location
     * (so it works even before the build materialized it), then queues the drafter on the worker.
     * Same honest grounding gate + queued path the pages board uses.
     */
    public function generatePage(string $locationId): void
    {
        $location = $this->ownedLocation($locationId);
        if ($location === null) {
            return;
        }

        $content = app(LocationLandingFactory::class)->findOrCreate($location);

        if (! app(GroundingReadiness::class)->ready($content)) {
            Notification::make()->warning()->title('Not ready yet')
                ->body('This location isn\'t ready to write yet — its details are still coming together.')->send();

            return;
        }

        GeneratePage::enqueue($content, actorId: Auth::id());
        Notification::make()->success()->title('Queued — generating on the worker')
            ->body("'{$content->title}' is being drafted; it will be ready to publish shortly.")->send();
    }

    /** Approve + publish the location's landing page (compose + push to WordPress, idempotent by ULID). */
    public function publishPage(string $locationId): void
    {
        $content = $this->landingFor($locationId);
        if ($content === null) {
            Notification::make()->warning()->title('Generate the page first')
                ->body('There\'s no page for this location yet — generate it, then publish.')->send();

            return;
        }

        $review = app(ReviewActions::class);
        $approve = $review->approve($content, Auth::id());
        if ($approve->isBlocked()) {
            Notification::make()->danger()->title('Cannot publish')->body((string) $approve->blockedReason)->send();

            return;
        }

        $publish = $review->publish($content->refresh(), Auth::id());
        if ($publish->isBlocked()) {
            Notification::make()->danger()->title('Cannot publish')->body((string) $publish->blockedReason)->send();

            return;
        }

        Notification::make()->success()->title('Publishing — composing and pushing to WordPress')->send();
    }

    /** Re-push an already-live location page — the same idempotent publish path, same URL. */
    public function repushPage(string $locationId): void
    {
        $content = $this->landingFor($locationId);
        if ($content === null) {
            return;
        }

        $result = app(ReviewActions::class)->publish($content, Auth::id());
        if ($result->isBlocked()) {
            Notification::make()->danger()->title('Cannot re-push')->body((string) $result->blockedReason)->send();

            return;
        }

        Notification::make()->success()->title('Re-pushing to WordPress')->send();
    }

    /**
     * Take the location's landing page down from WordPress — force-deletes the WP post (freeing the
     * slug) and flips the page back to republishable, so Repush recreates it on the SAME URL. The
     * plan row stays; only the live page is removed. Mirrors the core pages board's Take down.
     */
    public function takeDown(string $locationId): void
    {
        $content = $this->landingFor($locationId);
        if ($content === null) {
            return;
        }

        $result = app(DeleteFromWordpress::class)->delete($content);
        if (! $result['deleted'] && $result['on_wp']) {
            Notification::make()->danger()->title('Could not take it down')->body((string) $result['message'])->send();

            return;
        }

        Notification::make()->success()->title('Taken down — back in the work lane')
            ->body("'{$content->title}' was removed from WordPress; Repush recreates it on the same URL.")->send();
    }

    /**
     * Fix a stuck "-2/-3" permalink on the location's landing page. The landing slug is minted once by
     * {@see LocationLandingFactory} and reused forever, so a suffix it picked up during an earlier
     * collision never clears on its own (Take down + Repush just re-push the same slug). This recomputes
     * the clean slug from the page's title against the LIVE-only taken set (§417's partial index means a
     * removed page no longer reserves it), and — if a cleaner slug is now free — renames the row, drops a
     * 301 from the old URL, and republishes so WordPress renames the post on the same ID.
     */
    public function fixPermalink(string $locationId): void
    {
        $content = $this->landingFor($locationId);
        $site = $this->getSite();
        if ($content === null || $site === null) {
            return;
        }

        $current = ltrim((string) $content->slug, '/');
        // The clean slug the title wants — disambiguated against every OTHER live page (self excluded so
        // it can reclaim its own base). A removed/soft-deleted collider no longer counts (§417).
        $taken = array_values(array_filter(
            app(Permalinks::class)->takenSlugs($site),
            fn (string $s): bool => $s !== $current,
        ));
        $clean = app(Permalinks::class)->uniqueSlug((string) $content->title, $taken);

        if ($clean === $current) {
            Notification::make()->warning()->title('Already the cleanest available')
                ->body("/{$current}/ can't shorten — another LIVE page still holds the base URL. Use Diagnose to see which, remove it, then Fix again.")
                ->send();

            return;
        }

        $wasLive = (int) ($content->wp_post_id ?? 0) > 0;
        $oldPath = '/'.$current;
        $newPath = '/'.$clean;

        // 301 the old live URL to the new one (only if it was ever live, and not already redirected).
        if ($wasLive) {
            $exists = Redirect::withoutGlobalScope(SiteScope::class)
                ->where('site_id', $site->id)->where('from_url', $oldPath)->exists();
            if (! $exists) {
                Redirect::withoutGlobalScope(SiteScope::class)->create([
                    'site_id' => $site->id,
                    'from_url' => $oldPath,
                    'to_url' => $newPath,
                    'code' => 301,
                    'source' => RedirectSource::SlugChange->value,
                ]);
            }
        }

        $content->forceFill(['slug' => $clean])->save();

        // Push the rename to WordPress (idempotent by ULID — the post keeps its ID, gets the new slug).
        if ($wasLive) {
            $result = app(ReviewActions::class)->publish($content->refresh(), Auth::id());
            if ($result->isBlocked()) {
                Notification::make()->warning()->title("Renamed to /{$clean}/ — but the re-push was blocked")
                    ->body((string) $result->blockedReason.' Repush once resolved.')->send();

                return;
            }
        }

        Notification::make()->success()->title("Permalink fixed → /{$clean}/")
            ->body($wasLive
                ? "Re-pushing to WordPress; the old /{$current}/ now 301-redirects here."
                : "The page will publish at /{$clean}/.")
            ->send();
    }

    /**
     * Diagnose the location page against the LIVE site — the answer to "the URL has a -3 / the content
     * is wrong". Reads the real post state via §9-authed WordPress and reports WHY: pushes skipped
     * (locked / locally-edited), slug drift + who holds the clean slug, duplicate posts. Read-only.
     */
    public function diagnose(string $locationId): void
    {
        $content = $this->landingFor($locationId);
        if ($content === null) {
            Notification::make()->warning()->title('No page to diagnose')
                ->body('Generate the page first, then diagnose it.')->send();

            return;
        }

        try {
            $d = app(WordpressClientFactory::class)->forSite($content->site)->diagnoseContent((string) $content->id, (string) $content->slug);
        } catch (Throwable $e) {
            Notification::make()->danger()->title('Could not reach WordPress')->body($e->getMessage())->send();

            return;
        }

        if (empty($d['found'])) {
            $holder = is_array($d['slug_holder'] ?? null) ? $d['slug_holder'] : null;
            $body = 'No launchpad post exists on WordPress for this page yet — Generate + Publish it.'
                .($holder !== null ? " (Note: /{$content->slug}/ is already held by post #{$holder['wp_post_id']}.)" : '');
            Notification::make()->warning()->title('Not on WordPress')->body($body)->send();

            return;
        }

        $issues = [];
        if (! empty($d['push_would_skip'])) {
            $why = ($d['locked'] ?? false) ? 'LOCKED' : 'edited in WordPress (locally-edited)';
            $fix = ($d['locked'] ?? false) ? 'unlock it, then Repush' : 'Take down + Repush to overwrite';
            $issues[] = "⚠ Pushes are SKIPPED — the page is {$why}, so content + slug never update. Fix: {$fix}.";
        }
        if (! empty($d['slug_drifted'])) {
            $holder = is_array($d['slug_holder'] ?? null) ? $d['slug_holder'] : null;
            $who = $holder === null
                ? 'nothing else holds the clean slug — a Repush reclaims it (once it is not skipped above).'
                : ($holder['reclaimable'] ?? false
                    ? "post #{$holder['wp_post_id']} (launchpad) holds it — a Repush renames it aside and reclaims the slug."
                    : "post #{$holder['wp_post_id']} (UNMANAGED — a human/imported page) holds it — delete it in wp-admin, then Repush.");
            $issues[] = "⚠ URL drifted to /{$d['post_name']}/ (expected /{$d['expected_slug']}/): {$who}";
        }
        if ((int) ($d['duplicate_count'] ?? 1) > 1) {
            $issues[] = "⚠ {$d['duplicate_count']} posts carry this page's ID — Take down collapses them to one.";
        }

        $title = 'Live: '.($d['permalink'] ?? '').' ('.($d['status'] ?? '').')';
        if ($issues === []) {
            Notification::make()->success()->title($title)->body('✓ Clean — correct slug, not locked or locally-edited.')->send();

            return;
        }

        Notification::make()->warning()->title($title)->body(implode(' ', $issues))->persistent()->send();
    }

    /** A base Location owned by the working site, or null. */
    private function ownedLocation(string $locationId): ?Location
    {
        $site = $this->getSite();
        if ($site === null || $locationId === '') {
            return null;
        }

        return Location::withoutGlobalScope(SiteScope::class)
            ->where('site_id', $site->id)
            ->whereKey($locationId)
            ->first();
    }

    /** The existing landing page pinned to a location (not created here — generate does that). */
    private function landingFor(string $locationId): ?Content
    {
        $site = $this->getSite();
        if ($site === null || $locationId === '') {
            return null;
        }

        return Content::withoutGlobalScope(SiteScope::class)
            ->where('site_id', $site->id)
            ->where('page_type', PageType::Location->value)
            ->where('location_id', $locationId)
            ->first();
    }

    /** Territory is edited in the Service area workspace — deep link per card. */
    public function serviceAreaUrl(): string
    {
        // Territory edits happen on the new Setup's Locations step (same Location rows,
        // same shared coverage workspace) — not the retiring Settings page.
        return LocationsStep::getUrl();
    }

    /** Where a NEW dispatch point is added mid-contract — the Business step's GBP import. */
    public function addLocationUrl(): string
    {
        return BusinessStep::getUrl();
    }
}
