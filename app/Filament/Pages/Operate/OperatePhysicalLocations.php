<?php

namespace App\Filament\Pages\Operate;

use App\Build\PlanSync;
use App\ContentEngine\Drafting\GroundingReadiness;
use App\ContentEngine\Review\ReviewActions;
use App\Enums\ContentKind;
use App\Enums\ContentStatus;
use App\Enums\PageType;
use App\Filament\Pages\Gathering\BusinessStep;
use App\Filament\Pages\Gathering\LocationsStep;
use App\Jobs\GeneratePage;
use App\Locations\LocationLandingFactory;
use App\Models\Content;
use App\Models\CoverageArea;
use App\Models\Location;
use App\Models\Scopes\SiteScope;
use App\Models\Site;
use App\Operate\PhysicalLocations;
use App\Operate\QueueHealth;
use App\Publishing\DeleteFromWordpress;
use App\Publishing\PostPublisher;
use Filament\Notifications\Notification;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;

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
     * Background-worker health — so a stalled queue (approved pages that won't publish) is visible here
     * instead of looking like a broken button. Includes this tenant's brand for the drain hint.
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
     * @return array{summary: array<string, int>, cards: list<array<string, mixed>>}
     */
    public function getBoardProperty(): array
    {
        $site = $this->getSite();

        return $site === null
            ? ['summary' => ['locations' => 0, 'towns_covered' => 0, 'towns_selected' => 0, 'overlaps' => 0], 'cards' => []]
            : app(PhysicalLocations::class)->build($site);
    }

    // ── Town-page selection + bulk generate/publish (per GBP location) ──────

    /** The most towns one "Generate + publish" click will queue before it asks the operator to confirm. */
    private const BATCH_CONFIRM_AT = 25;

    /** Toggle one town's page_selected flag (the plan + the card's checkbox are the same source). */
    public function toggleTown(string $coverageAreaId): void
    {
        $site = $this->getSite();
        if ($site === null) {
            return;
        }

        $area = CoverageArea::withoutGlobalScope(SiteScope::class)
            ->where('site_id', $site->id)->whereKey($coverageAreaId)->first();
        $area?->forceFill(['page_selected' => ! $area->page_selected])->save();
    }

    /** Select (or clear) every town in a display band for a location — the bulk-select control. */
    public function selectBand(string $locationId, string $band, bool $select): void
    {
        $site = $this->getSite();
        if ($site === null) {
            return;
        }

        $card = collect(app(PhysicalLocations::class)->build($site)['cards'])->firstWhere('id', $locationId);
        $ids = collect($card['town_bands'][$band] ?? [])->pluck('coverage_area_id')->all();
        if ($ids !== []) {
            CoverageArea::withoutGlobalScope(SiteScope::class)
                ->where('site_id', $site->id)->whereIn('id', $ids)->update(['page_selected' => $select]);
        }
    }

    /**
     * Generate this location's selected town pages as DRAFTS for review — the gated path. Queues one
     * GeneratePage (autoPublish OFF) per selected town not already live/in flight; the worker drafts
     * (Sonnet + fal) and parks each in review. The operator then eyeballs each town and clicks "Publish
     * reviewed" (below) to push them live. Nothing here goes straight to WordPress.
     */
    public function generateSelected(string $locationId): void
    {
        $this->enqueueSelected($locationId, autoPublish: false);
    }

    /**
     * Generate + publish this location's selected town pages in one shot (the fast path, no review gate).
     * Queues one GeneratePage(autoPublish) per selected town not already live/in flight — the worker
     * drafts and, on a CLEAN draft, publishes immediately; a thin draft still parks in review, never live.
     */
    public function generateAndPublishSelected(string $locationId): void
    {
        $this->enqueueSelected($locationId, autoPublish: true);
    }

    /**
     * Materialize any newly-selected town into a page (idempotent Sync plan), then enqueue a GeneratePage
     * per selected, not-already-live/in-flight town. autoPublish decides whether a clean draft publishes
     * itself (fast path) or parks in review (gated path).
     */
    private function enqueueSelected(string $locationId, bool $autoPublish): void
    {
        $site = $this->getSite();
        if ($site === null) {
            return;
        }

        // Bring newly-selected towns into being as pages (candidate rows) before we can generate them.
        app(PlanSync::class)->sync($site);

        $card = collect(app(PhysicalLocations::class)->build($site)['cards'])->firstWhere('id', $locationId);
        if ($card === null) {
            return;
        }

        $queued = 0;
        $notReady = 0;
        foreach (['larger', 'mid', 'smaller'] as $band) {
            foreach ($card['town_bands'][$band] ?? [] as $town) {
                if (! $town['page_selected'] || in_array($town['status'], ['published', 'generating'], true) || $town['content_id'] === null) {
                    continue;
                }
                $content = $this->ownedContent((string) $town['content_id']);
                if ($content === null) {
                    continue;
                }
                if (! app(GroundingReadiness::class)->ready($content)) {
                    $notReady++;

                    continue;
                }
                GeneratePage::enqueue($content, actorId: Auth::id(), autoPublish: $autoPublish);
                $queued++;
            }
        }

        if ($queued === 0) {
            Notification::make()->info()->title('Nothing to generate')
                ->body($notReady > 0 ? "{$notReady} selected town(s) aren't ready to write yet." : 'Every selected town here is already live or in flight.')->send();

            return;
        }

        $verb = $autoPublish ? 'Generating + publishing' : 'Generating drafts for';
        $tail = $autoPublish
            ? 'Watch the progress monitor up top; each publishes as its draft lands.'
            : 'Watch the monitor; review each draft, then "Publish reviewed" to push them live.';
        Notification::make()->success()->title("{$verb} {$queued} town page(s)")
            ->body(($notReady > 0 ? "{$notReady} not ready were skipped. " : '').$tail)->send();
    }

    /**
     * The review-gate publish: push this location's reviewed (drafted-but-not-live) selected town pages
     * live NOW, synchronously — approve each (the same publish-validation guard), then publish inline via
     * PostPublisher (idempotent by ULID). Runs on the console clock here, so it works even with the queue
     * worker down; bounded per click so a huge batch can't time out the request.
     */
    public function publishReviewedSelected(string $locationId): void
    {
        $site = $this->getSite();
        if ($site === null) {
            return;
        }

        $card = collect(app(PhysicalLocations::class)->build($site)['cards'])->firstWhere('id', $locationId);
        if ($card === null) {
            return;
        }

        $ids = [];
        foreach (['larger', 'mid', 'smaller'] as $band) {
            foreach ($card['town_bands'][$band] ?? [] as $town) {
                if ($town['page_selected'] && $town['status'] === 'drafted' && $town['content_id'] !== null) {
                    $ids[] = (string) $town['content_id'];
                }
            }
        }
        $ids = array_slice($ids, 0, self::BATCH_CONFIRM_AT);

        if ($ids === []) {
            Notification::make()->info()->title('Nothing to publish')
                ->body('No reviewed drafts here yet — generate drafts first, then review them.')->send();

            return;
        }

        [$published, $blocked] = $this->publishInline($ids);

        Notification::make()->success()->title("Published {$published} of ".count($ids).' reviewed town page(s)')
            ->body(($blocked > 0 ? "{$blocked} were blocked (a required image failed to render). " : '')
                .(count($ids) === self::BATCH_CONFIRM_AT ? 'More may remain — click again to continue.' : 'Done.'))->send();
    }

    /**
     * Publish ONE reviewed town page live now, synchronously — the per-town approve→publish button. Same
     * guard + inline PostPublisher path as the batch, keyed to the town's content id.
     */
    public function publishTown(string $contentId): void
    {
        $content = $this->ownedContent($contentId);
        if ($content === null) {
            return;
        }

        [$published, $blocked] = $this->publishInline([$contentId]);

        if ($blocked > 0) {
            Notification::make()->danger()->title('Cannot publish')
                ->body('A required image failed to render — regenerate before publishing.')->send();

            return;
        }

        Notification::make()->{$published > 0 ? 'success' : 'warning'}()
            ->title($published > 0 ? "Published '{$content->title}'" : 'Nothing published')
            ->body($published > 0 ? 'Live on WordPress.' : 'This town has no completed draft yet — generate it first.')->send();
    }

    /**
     * Approve + publish the given town pages inline (synchronous), honoring the review guard. A drafted
     * page is approved (flips to approved, running the required-image gate) then pushed via PostPublisher.
     * Returns [published, blocked].
     *
     * @param  list<string>  $contentIds
     * @return array{0: int, 1: int}
     */
    private function publishInline(array $contentIds): array
    {
        $review = app(ReviewActions::class);
        $publisher = app(PostPublisher::class);
        $actor = Auth::id();
        $published = 0;
        $blocked = 0;

        foreach ($contentIds as $id) {
            $content = $this->ownedContent($id);
            if ($content === null || ! $content->hasDraft()) {
                continue;
            }

            if ($content->status !== ContentStatus::Published) {
                $approve = $review->approve($content, $actor);
                if ($approve->isBlocked()) {
                    $blocked++;

                    continue;
                }
                $content = $content->refresh();
            }

            if ($publisher->publish($content, $actor)->isPublished()) {
                $published++;
            }
        }

        return [$published, $blocked];
    }

    /**
     * Re-draft ONE town whose draft the operator rejected on review — clears any stuck generating
     * stamp + last draft error and drops its dead/queued job, then re-queues GeneratePage (draft-only,
     * no auto-publish). The fresh draft lands back in the review list. Needs a running worker to draft.
     */
    public function regenerateTown(string $contentId): void
    {
        $content = $this->ownedContent($contentId);
        if ($content === null) {
            return;
        }

        if (! app(GroundingReadiness::class)->ready($content)) {
            Notification::make()->warning()->title('Not ready yet')
                ->body('This town isn\'t ready to re-write yet — its details are still coming together.')->send();

            return;
        }

        $this->clearGeneratingMark($content);   // so a town stuck ⏳ behind a dead worker can be re-kicked
        $this->flushJobsFor($contentId);         // drop the old/dead job before re-queuing
        GeneratePage::enqueue($content->refresh(), actorId: Auth::id(), autoPublish: false);

        Notification::make()->success()->title('Regenerating')
            ->body("'{$content->title}' is being re-drafted; review it again once the worker finishes.")->send();
    }

    /**
     * Dump ONE town out of the review/publish set — a draft that failed the operator's review. Rejects
     * it (so it leaves the drafted list and never publishes) and clears any queued/stuck job so it can't
     * re-fire. It stays page-selected, so "Generate drafts" can re-draft it fresh later if wanted.
     */
    public function discardTown(string $contentId): void
    {
        $content = $this->ownedContent($contentId);
        if ($content === null) {
            return;
        }

        app(ReviewActions::class)->reject($content, 'Discarded on review (Operate → Locations).');
        $this->clearGeneratingMark($content->refresh());
        $this->flushJobsFor($contentId);

        Notification::make()->success()->title('Discarded')
            ->body("'{$content->title}' was pulled from the review/publish set. Use \"Generate drafts\" to re-draft it fresh.")->send();
    }

    /**
     * Clear this location's town pages stuck "generating" behind a dead worker — resets the generating
     * stamp and kills their queued jobs so they're actionable again (regenerate / discard / re-select).
     * The escape hatch for a backlog that piled up while the worker was down.
     */
    public function clearStuckGenerating(string $locationId): void
    {
        $site = $this->getSite();
        $location = $this->ownedLocation($locationId);
        if ($site === null || $location === null) {
            return;
        }

        $stuck = Content::withoutGlobalScope(SiteScope::class)
            ->where('site_id', $site->id)
            ->where('kind', ContentKind::Page->value)
            ->where('page_type', PageType::Location->value)
            ->where('parent_location_id', $location->id)
            ->get()
            ->filter(fn (Content $c): bool => $c->generationState() === 'generating');

        if ($stuck->isEmpty()) {
            Notification::make()->info()->title('Nothing stuck')
                ->body('No town pages are stuck generating for this location.')->send();

            return;
        }

        foreach ($stuck as $c) {
            $this->clearGeneratingMark($c);
            $this->flushJobsFor((string) $c->id);
        }

        Notification::make()->success()->title("Cleared {$stuck->count()} stuck-generating town page(s)")
            ->body('They\'re actionable again — regenerate or discard them. Fix the worker (queue:work) so drafting runs automatically.')->send();
    }

    /** Reset a page's generation-state marker (generating stamp + draft-error) so it's actionable again. */
    private function clearGeneratingMark(Content $content): void
    {
        $meta = is_array($content->meta) ? $content->meta : [];
        unset($meta['generating_at'], $meta['draft_error'], $meta['draft_failure'], $meta['draft_failed_at']);
        $content->forceFill(['meta' => $meta])->save();
    }

    /** Delete the pending + dead queue rows whose serialized payload references this content id. */
    private function flushJobsFor(string $contentId): void
    {
        DB::table('jobs')->where('payload', 'like', '%'.$contentId.'%')->delete();
        DB::table('failed_jobs')->where('payload', 'like', '%'.$contentId.'%')->delete();
    }

    /**
     * Escape hatch when the worker is down: publish this location's in-flight town pages SYNCHRONOUSLY,
     * right now, via the same PostPublisher the drain command uses. Bounded per click so a huge backlog
     * can't time out the request — for more, click again or run launchpad:drain-publish.
     */
    public function drainNow(string $locationId): void
    {
        $site = $this->getSite();
        $location = $this->ownedLocation($locationId);
        if ($site === null || $location === null) {
            return;
        }

        $inflight = Content::withoutGlobalScope(SiteScope::class)
            ->where('site_id', $site->id)
            ->where('kind', ContentKind::Page->value)
            ->where('page_type', PageType::Location->value)
            ->where('parent_location_id', $location->id)
            ->whereIn('status', [ContentStatus::Approved->value, ContentStatus::Rendering->value, ContentStatus::Publishing->value])
            ->orderBy('updated_at')
            ->limit(self::BATCH_CONFIRM_AT)
            ->get();

        if ($inflight->isEmpty()) {
            Notification::make()->info()->title('Nothing to drain')->body('No town pages are stuck waiting to publish for this location.')->send();

            return;
        }

        $publisher = app(PostPublisher::class);
        $published = 0;
        foreach ($inflight as $page) {
            if ($publisher->publish($page, Auth::id())->isPublished()) {
                $published++;
            }
        }

        Notification::make()->success()->title("Published {$published} of {$inflight->count()} stuck page(s)")
            ->body($inflight->count() === self::BATCH_CONFIRM_AT ? 'More may remain — click again to continue.' : 'Fix the worker (Horizon / queue:work) so this is automatic.')->send();
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
