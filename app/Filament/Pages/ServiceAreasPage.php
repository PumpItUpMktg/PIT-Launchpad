<?php

namespace App\Filament\Pages;

use App\Filament\Pages\Concerns\BuildsTownPage;
use App\GeoGrid\CoverageRunAll;
use App\Jobs\RunCoverageScan;
use App\Models\GeoGridScan;
use App\Models\Keyword;
use App\Models\Location;
use App\Models\Scopes\SiteScope;
use App\Models\Site;
use App\Operator\ActiveTenant;
use App\TownRank\ServiceAreas;
use App\TownRank\TownRankBoard;
use BackedEnum;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Log;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Url;

/**
 * Service Areas (§ Town Rank): one page per GBP service area — a physical location and the counties it
 * serves. The list is one card per area; an area opens to one card per tracked keyword with the website's
 * town-rank map and the GBP's map-pack map side by side over the same towns, and a metrics slot for the
 * scoring the operator will define once the data has been seen. Website scans are run from Town Rank; the
 * GBP report (one Maps search per town from the town's coordinates — a coverage scan) runs from the card.
 *
 * A dot on either map selects its town: the card opens the same town detail the Town Rank board shows
 * (both website modes, who outranks us, the page state, the map-pack rank, and the suggested actions).
 *
 * @property-read list<array<string, mixed>> $areas
 * @property-read array<string, mixed>|null $area
 * @property-read array<string, mixed>|null $town
 */
class ServiceAreasPage extends Page
{
    use BuildsTownPage;

    protected static string|BackedEnum|null $navigationIcon = 'heroicon-o-map';

    protected static ?string $navigationLabel = 'Service areas';

    protected static ?string $title = 'Service Areas';

    protected static string|\UnitEnum|null $navigationGroup = 'Results';

    protected static ?string $slug = 'service-areas';

    protected string $view = 'filament.pages.service-areas';

    public ?string $siteId = null;

    #[Url]
    public ?string $locationId = null;

    /** The card whose town is selected. */
    #[Url]
    public ?string $keywordId = null;

    #[Url]
    public ?string $townId = null;

    public static function menuTag(): string
    {
        return 'unaddressed';
    }

    public static function canAccess(): bool
    {
        return Auth::user()?->canOperate() ?? false;
    }

    public function mount(): void
    {
        $this->siteId = app(ActiveTenant::class)->id();
    }

    public function openArea(string $id): void
    {
        $this->locationId = $id;
    }

    public function closeArea(): void
    {
        $this->locationId = null;
        $this->keywordId = null;
        $this->townId = null;
    }

    /** A dot on a card's map: open that town's detail under the card (clicking it again closes it). */
    public function selectTown(string $keywordId, string $townId): void
    {
        if ($this->keywordId === $keywordId && $this->townId === $townId) {
            $this->clearTown();

            return;
        }
        $this->keywordId = $keywordId;
        $this->townId = $townId;
    }

    public function clearTown(): void
    {
        $this->keywordId = null;
        $this->townId = null;
    }

    /** The selected town's detail for the selected card — the Town Rank board's town panel; null when none. */
    public function getTownProperty(): ?array
    {
        $site = $this->site();
        if ($site === null || $this->locationId === null || $this->keywordId === null || $this->townId === null) {
            return null;
        }

        return app(TownRankBoard::class)->town($site, $this->keywordId, $this->townId);
    }

    /**
     * "Run GBP report" on a card: post one coverage-mode geo-grid scan for this area's location × keyword
     * (the same scan the coverage plans run), unless one is already collecting. Results land through the
     * IngestCoverageScans sweep; the card's GBP column fills as they do.
     */
    /**
     * What a whole-office GBP run would cost. Same plan() the run uses, so the figure beside the button is
     * the figure that gets posted — and a keyword already collecting is excluded from both.
     *
     * @return array{towns: int, runnable: int, tracked: int, pending: int, requests: int, cost: float, over_ceiling: bool, ceiling: int, balance: float|null, affordable: bool}|null
     */
    #[Computed]
    public function gbpPlan(): ?array
    {
        $site = $this->site();
        $location = $site === null || $this->locationId === null ? null
            : Location::withoutGlobalScope(SiteScope::class)->where('site_id', $site->id)->whereKey($this->locationId)->first();
        if ($site === null || $location === null) {
            return null;
        }

        $plan = app(CoverageRunAll::class)->plan($site, $location);

        return [
            'towns' => $plan['towns'],
            'runnable' => count($plan['keywords']),
            'tracked' => $plan['tracked'],
            'pending' => $plan['pending'],
            'requests' => $plan['requests'],
            'cost' => $plan['cost'],
            'over_ceiling' => $plan['over_ceiling'],
            'ceiling' => $plan['ceiling'],
            'balance' => $plan['balance'],
            'affordable' => $plan['affordable'],
        ];
    }

    /** Queue the GBP report for every tracked keyword at THIS office — one button per location. */
    public function runAllGbp(): void
    {
        $site = $this->site();
        $location = $site === null || $this->locationId === null ? null
            : Location::withoutGlobalScope(SiteScope::class)->where('site_id', $site->id)->whereKey($this->locationId)->first();
        if ($site === null || $location === null) {
            return;
        }

        $result = app(CoverageRunAll::class)->run($site, $location);
        unset($this->gbpPlan);

        if ($result['over_ceiling']) {
            Notification::make()->warning()->title('Not queued')
                ->body(sprintf('%s requests is over the ceiling — narrow the tracked keywords or raise launchpad.geo_grid.request_ceiling.', number_format($result['requests'])))->send();

            return;
        }
        if (! $result['affordable']) {
            Notification::make()->danger()->title('Not queued — DataForSEO balance too low')
                ->body(sprintf('This run needs ~$%s and the account holds $%s. Top up, then run it again; nothing was posted.',
                    number_format($result['cost'], 2), number_format((float) $result['balance'], 2)))->send();

            return;
        }
        if ($result['queued'] === 0) {
            Notification::make()->warning()->title('Nothing to queue')
                ->body('Every tracked keyword here is already collecting.')->send();

            return;
        }

        Notification::make()->success()
            ->title(sprintf('Posting %s GBP report(s) · %s requests (~$%s)', number_format($result['queued']), number_format($result['requests']), number_format($result['cost'], 2)))
            ->body('One Maps search per town per keyword; each card fills in as results land.')->send();
    }

    public function runGbp(string $keywordId): void
    {
        $site = $this->site();
        $location = $site === null || $this->locationId === null ? null
            : Location::withoutGlobalScope(SiteScope::class)->where('site_id', $site->id)->whereKey($this->locationId)->first();
        $keyword = $site === null ? null : Keyword::withoutGlobalScope(SiteScope::class)->where('site_id', $site->id)->whereKey($keywordId)->first();
        if ($site === null || $location === null || $keyword === null) {
            return;
        }

        $latest = GeoGridScan::withoutGlobalScope(SiteScope::class)
            ->where('site_id', $site->id)->where('location_id', $location->id)->where('keyword_id', $keyword->id)->where('mode', 'coverage')
            ->orderByDesc('scanned_at')->first();
        if ($latest !== null && $latest->status === 'pending') {
            Notification::make()->warning()->title('Not queued')->body('A GBP report is already collecting for this keyword here — results land within a few minutes.')->send();

            return;
        }

        RunCoverageScan::dispatch((string) $location->id, (string) $keyword->id);
        Log::info('Service areas: GBP report queued.', ['site_id' => $site->id, 'location_id' => $location->id, 'keyword_id' => $keyword->id, 'query' => (string) $keyword->query]);
        Notification::make()->success()
            ->title(sprintf('Posting the GBP report for “%s” in %s', $keyword->query, $location->name))
            ->body('One Maps search per town from the town’s coordinates; the card fills in as results land.')->send();
    }

    /** @return list<array<string, mixed>> */
    public function getAreasProperty(): array
    {
        $site = $this->site();

        return $site !== null ? app(ServiceAreas::class)->areas($site) : [];
    }

    /** The selected area's page; null on the list, or when the id isn't one of this site's locations. */
    public function getAreaProperty(): ?array
    {
        $site = $this->site();
        if ($site === null || $this->locationId === null) {
            return null;
        }

        return app(ServiceAreas::class)->area($site, $this->locationId);
    }

    private function site(): ?Site
    {
        return $this->siteId !== null ? Site::withoutGlobalScopes()->find($this->siteId) : null;
    }
}
