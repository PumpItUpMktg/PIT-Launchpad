<?php

namespace App\Filament\Pages\Operate;

use App\ContentEngine\Drafting\GroundingReadiness;
use App\ContentEngine\Review\ReviewActions;
use App\Enums\ContentKind;
use App\Enums\ContentStatus;
use App\Filament\Pages\Gathering\BusinessStep;
use App\Filament\Pages\Gathering\LocationsStep;
use App\Jobs\GeneratePage;
use App\Locations\LocationLandingFactory;
use App\Models\Content;
use App\Models\Location;
use App\Models\Scopes\SiteScope;
use App\Models\Site;
use App\Operate\PhysicalLocations;
use App\Publishing\DeleteFromWordpress;
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

    /**
     * Re-push the location's landing page — the standard idempotent-by-ULID publish path, content-keyed
     * to match the shared card actions. Works before the page is live too: a not-yet-published page is
     * approved first, then published (so "Repush" doubles as the first publish); an already-live page
     * republishes on the same URL.
     */
    public function repush(string $contentId): void
    {
        $content = $this->ownedContent($contentId);
        if ($content === null) {
            return;
        }

        $review = app(ReviewActions::class);

        if ($content->status !== ContentStatus::Published) {
            $approve = $review->approve($content, Auth::id());
            if ($approve->isBlocked()) {
                Notification::make()->danger()->title('Cannot publish')->body((string) $approve->blockedReason)->send();

                return;
            }
            $content = $content->refresh();
        }

        $result = $review->publish($content, Auth::id());
        if ($result->isBlocked()) {
            Notification::make()->danger()->title('Cannot publish')->body((string) $result->blockedReason)->send();

            return;
        }

        Notification::make()->success()->title('Publishing — composing and pushing to WordPress')->send();
    }

    /** Re-draft the location's landing page on the worker (same honest grounding gate as the pages board). */
    public function regenerate(string $contentId): void
    {
        $content = $this->ownedContent($contentId);
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
            ->body("'{$content->title}' is being re-drafted; review it once it finishes.")->send();
    }

    /**
     * Take the location's landing page down from WordPress — force-deletes the WP post (freeing the
     * slug) and flips it back to republishable, so Repush recreates it on the SAME URL. The plan row
     * stays; only the live page is removed. Content-keyed to match the shared card actions.
     */
    public function takeDown(string $contentId): void
    {
        $content = $this->ownedContent($contentId);
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

    /** A page Content owned by the working site, or null — the target of the content-keyed card actions. */
    private function ownedContent(string $contentId): ?Content
    {
        $site = $this->getSite();
        if ($site === null || $contentId === '') {
            return null;
        }

        return Content::withoutGlobalScope(SiteScope::class)
            ->where('site_id', $site->id)
            ->where('kind', ContentKind::Page->value)
            ->whereKey($contentId)
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
