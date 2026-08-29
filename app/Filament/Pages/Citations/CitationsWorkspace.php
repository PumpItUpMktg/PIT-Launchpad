<?php

namespace App\Filament\Pages\Citations;

use App\Citations\CitationLifecycle;
use App\Citations\Ui\LocationWorkspace;
use App\Citations\Ui\WorkspaceRow;
use App\Models\CitationEvent;
use App\Models\CitationStatus;
use App\Models\Location;
use App\Models\Scopes\SiteScope;
use App\Models\TenantDirectoryExclusion;
use App\Support\CurrentSite;
use BackedEnum;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Auth;

/**
 * Citations · Location workspace (§ Citations UI, PR C) — the directory-by-directory work surface for one
 * location. Rows are ordered by the default work priority (mismatch first), filterable, with a right drawer
 * per row (NAP diff + lifecycle timeline + actions). Bulk-select submittable rows to record a work order;
 * "Mark not relevant" writes a tenant exclusion that applies to every one of that tenant's listings.
 *
 * @property-read array{stats: array<string, int>, rows: list<WorkspaceRow>} $workspace
 */
class CitationsWorkspace extends Page
{
    protected static bool $shouldRegisterNavigation = false; // reached from the tenant board, not the sidebar

    protected static string|BackedEnum|null $navigationIcon = 'heroicon-o-list-bullet';

    protected static ?string $slug = 'citations/workspace';

    protected string $view = 'filament.citations.workspace';

    public ?string $locationId = null;

    public string $filter = 'all';

    public string $search = '';

    public bool $showNotRelevant = false;

    public ?string $activeDirectoryId = null;

    public static function menuTag(): string
    {
        return 'unaddressed';
    }

    public function mount(?string $location = null): void
    {
        $requested = $location ?? request()->query('location');
        $this->locationId = is_string($requested) ? $requested : null;
        $location = $this->getLocation();
        if ($location !== null) {
            CurrentSite::set((string) $location->site_id);
        }
    }

    public function getLocation(): ?Location
    {
        return $this->locationId === null
            ? null
            : Location::query()->withoutGlobalScope(SiteScope::class)->find($this->locationId);
    }

    /** @return array{stats: array<string, int>, rows: list<WorkspaceRow>} */
    public function getWorkspaceProperty(): array
    {
        $location = $this->getLocation();
        if ($location === null) {
            return ['stats' => [], 'rows' => []];
        }
        CurrentSite::set((string) $location->site_id);
        $data = app(LocationWorkspace::class)->forLocation($location, includeNotRelevant: $this->showNotRelevant);

        $rows = array_values(array_filter($data['rows'], function (WorkspaceRow $r): bool {
            $matchesFilter = match ($this->filter) {
                'needs_action' => in_array($r->chip['key'], ['mismatch', 'missing', 'stalled', 'rejected'], true),
                'mismatch' => $r->chip['key'] === 'mismatch',
                'missing' => $r->chip['key'] === 'missing',
                'in_flight' => $r->chip['key'] === 'submitted',
                'local' => $r->isLocal,
                default => true,
            };
            $matchesSearch = $this->search === '' || str_contains(mb_strtolower($r->directoryName), mb_strtolower($this->search));

            return $matchesFilter && $matchesSearch;
        }));

        return ['stats' => $data['stats'], 'rows' => $rows];
    }

    public function openRow(string $directoryId): void
    {
        $this->activeDirectoryId = $directoryId;
    }

    public function closeRow(): void
    {
        $this->activeDirectoryId = null;
    }

    /** @return array{status: ?CitationStatus, events: Collection<int, CitationEvent>}|null */
    public function getActiveDetailProperty(): ?array
    {
        $location = $this->getLocation();
        if ($this->activeDirectoryId === null || $location === null) {
            return null;
        }

        $status = CitationStatus::query()->withoutGlobalScope(SiteScope::class)
            ->where('location_id', $location->id)->where('directory_id', $this->activeDirectoryId)->first();

        $events = CitationEvent::query()->withoutGlobalScope(SiteScope::class)
            ->where('location_id', $location->id)->where('directory_id', $this->activeDirectoryId)
            ->latest('occurred_at')->get();

        return ['status' => $status, 'events' => $events];
    }

    /**
     * Record that the selected citations were issued in a work order (bumps their count → stalled if it keeps
     * happening). The prioritized PDF/CSV batch is produced by launchpad:citation-work-order.
     *
     * @param  list<string>  $statusIds
     */
    public function createWorkOrders(array $statusIds): void
    {
        $lifecycle = app(CitationLifecycle::class);
        $count = 0;
        foreach ($statusIds as $id) {
            $status = CitationStatus::query()->withoutGlobalScope(SiteScope::class)->find($id);
            if ($status !== null) {
                $lifecycle->recordWorkOrderIssued($status);
                $count++;
            }
        }

        Notification::make()->success()->title("Added {$count} directories to the work order")->send();
    }

    /** Mark a directory not relevant for the whole tenant — it leaves eligibility for EVERY listing they own. */
    public function markNotRelevant(string $directoryId): void
    {
        $location = $this->getLocation();
        if ($location === null) {
            return;
        }

        TenantDirectoryExclusion::query()->updateOrCreate(
            ['site_id' => $location->site_id, 'directory_id' => $directoryId],
            ['excluded_by' => Auth::id(), 'excluded_at' => now()],
        );

        $this->activeDirectoryId = null;
        Notification::make()->warning()->title('Marked not relevant for the whole tenant')
            ->body('This directory is now excluded from every one of this client\'s listings.')->send();
    }
}
