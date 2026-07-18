<?php

namespace App\Filament\Pages\Operate;

use App\ContentEngine\Drafting\GroundingReadiness;
use App\ContentEngine\Review\ReviewActions;
use App\Enums\PageType;
use App\Filament\Pages\Gathering\BusinessStep;
use App\Filament\Pages\Gathering\LocationsStep;
use App\Jobs\GeneratePage;
use App\Locations\LocationLandingFactory;
use App\Models\Content;
use App\Models\Location;
use App\Models\Scopes\SiteScope;
use App\Models\Site;
use App\Operate\PhysicalLocations;
use Filament\Notifications\Notification;
use Illuminate\Support\Facades\Auth;

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
