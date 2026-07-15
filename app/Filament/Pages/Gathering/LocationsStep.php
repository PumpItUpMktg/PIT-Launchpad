<?php

namespace App\Filament\Pages\Gathering;

use App\Locations\Concerns\ManagesLocationCoverage;
use App\Models\CoverageArea;
use App\Models\Location;
use App\Models\Scopes\SiteScope;
use Filament\Notifications\Notification;

/**
 * New Setup · Step 3 — Locations. The FULL territory workspace, embedded (the same
 * {@see ManagesLocationCoverage} trait + partials the Service-area page renders): location tabs,
 * the county multi-select (home county pre-ticked), towns grouped by size with tap-to-select
 * page picking, add-a-town, and the shared coverage map. One location's territory at a time —
 * the tabs are the anti-clutter design.
 *
 * The gathering layer rides in the active location's panel: the interview's coverage
 * suggestions as a prompt above the picker, the assigned town-page list (read-only here; the
 * fine-grained editor stays in Settings → Locations), market notes + storefront with
 * "from interview" chips — and SAVING CONFIRMS seeded fields, as on every review surface.
 */
class LocationsStep extends GatheringPage
{
    use ManagesLocationCoverage;

    protected static ?string $slug = 'setup2/locations';

    protected static ?string $navigationLabel = 'Locations';

    protected static ?int $navigationSort = 3;

    protected string $view = 'filament.gathering.locations-step';

    /** @var array<string, string> locationId => market notes */
    public array $notes = [];

    /** @var array<string, bool> locationId => storefront flag */
    public array $storefront = [];

    protected function afterSiteResolved(): void
    {
        $this->reset(['manualLat', 'manualLng', 'computed', 'adding', 'addName', 'addAddress', 'addQuery', 'placeResults', 'activeTab', 'townQuery', 'townResults']);
        $this->enterCoverageWorkspace();

        $this->notes = [];
        $this->storefront = [];
        foreach ($this->locations as $location) {
            $this->notes[$location->id] = (string) ($location->market_notes ?? '');
            $this->storefront[$location->id] = (bool) $location->is_storefront;
        }
    }

    /** Save the gathering details for one location — and confirm what the interview seeded. */
    public function saveDetails(string $locationId): void
    {
        $location = $this->owned($locationId);
        if ($location === null) {
            return;
        }

        $location->forceFill([
            'market_notes' => trim((string) ($this->notes[$locationId] ?? '')) !== '' ? trim((string) $this->notes[$locationId]) : null,
            'is_storefront' => (bool) ($this->storefront[$locationId] ?? false),
        ])->save();

        // Saving IS confirming — the operator just reviewed this location's territory + notes.
        $this->confirmSeeded($location, ['served_towns', 'market_notes']);

        Notification::make()->success()->title("{$location->name} saved")->send();
    }

    /** Suggestions handled — clear the extraction prompts for this location. */
    public function dismissSuggestions(string $locationId): void
    {
        $location = $this->owned($locationId);
        $location?->forceFill(['coverage_suggestions' => null])->save();
    }

    /** @return array{state: 'complete'|'attention'|'empty', label: string} */
    public function readiness(): array
    {
        $locations = $this->getLocationsProperty();
        if ($locations->isEmpty()) {
            return ['state' => 'empty', 'label' => 'No locations yet — import them on Business'];
        }

        // Territory = computed coverage towns (the workspace) OR an assigned town-page list
        // (pre-workspace tenants) — either counts.
        $covered = CoverageArea::withoutGlobalScope(SiteScope::class)
            ->where('site_id', (string) $this->siteId)
            ->get();

        $withTerritory = $locations->filter(function (Location $l) use ($covered) {
            $hasCoverage = $covered->contains(fn (CoverageArea $a) => is_array($a->source_location_ids)
                && in_array($l->id, array_map('strval', $a->source_location_ids), true));

            return $hasCoverage || collect($l->served_towns ?? [])->isNotEmpty();
        })->count();

        if ($withTerritory === $locations->count()) {
            return ['state' => 'complete', 'label' => 'Complete'];
        }

        return ['state' => 'attention', 'label' => ($locations->count() - $withTerritory).' location(s) without a territory yet'];
    }

    private function owned(string $locationId): ?Location
    {
        return $this->siteId === null ? null : Location::withoutGlobalScope(SiteScope::class)
            ->where('site_id', $this->siteId)
            ->whereKey($locationId)
            ->first();
    }
}
