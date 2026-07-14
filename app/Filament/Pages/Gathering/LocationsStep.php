<?php

namespace App\Filament\Pages\Gathering;

use App\Locations\ServedTowns;
use App\Models\Location;
use App\Models\Scopes\SiteScope;
use Filament\Notifications\Notification;
use Illuminate\Database\Eloquent\Collection;

/**
 * New Setup · Step 3 — Locations review surface. Per-location card over the SAME Location records:
 * NAP summary, storefront flag, served-towns input (one "Town, ST" per line; one-town-one-location
 * enforced via {@see ServedTowns::conflicts}), market notes. Interview-seeded values render with a
 * "from interview" chip; unresolved coverage phrases + conflicting candidates from extraction show
 * as a prompt above the towns input. Saving confirms — the operator's job is confirm + gap-fill,
 * not data entry.
 *
 * @property-read Collection<int, Location> $locations
 */
class LocationsStep extends GatheringPage
{
    protected static ?string $slug = 'setup2/locations';

    protected static ?string $navigationLabel = 'Locations';

    protected static ?int $navigationSort = 3;

    protected string $view = 'filament.gathering.locations-step';

    /** @var array<string, string> locationId => served-towns textarea (one per line) */
    public array $towns = [];

    /** @var array<string, string> locationId => market notes */
    public array $notes = [];

    /** @var array<string, bool> locationId => storefront flag */
    public array $storefront = [];

    protected function afterSiteResolved(): void
    {
        $this->towns = [];
        $this->notes = [];
        $this->storefront = [];
        foreach ($this->getLocationsProperty() as $location) {
            $this->towns[$location->id] = collect($location->served_towns ?? [])
                ->map(fn ($t) => trim((string) ($t['name'] ?? '')).(trim((string) ($t['state'] ?? '')) !== '' ? ', '.trim((string) $t['state']) : ''))
                ->filter()
                ->implode("\n");
            $this->notes[$location->id] = (string) ($location->market_notes ?? '');
            $this->storefront[$location->id] = (bool) $location->is_storefront;
        }
    }

    /** @return Collection<int, Location> */
    public function getLocationsProperty(): Collection
    {
        if ($this->siteId === null) {
            return new Collection;
        }

        return Location::withoutGlobalScope(SiteScope::class)
            ->where('site_id', $this->siteId)
            ->orderBy('name')
            ->get();
    }

    public function saveLocation(string $locationId): void
    {
        $location = $this->owned($locationId);
        if ($location === null) {
            return;
        }

        $entries = collect(preg_split('/\r?\n/', (string) ($this->towns[$locationId] ?? '')) ?: [])
            ->map(fn ($l) => trim((string) $l))
            ->filter()
            ->values();

        // The one-town-one-location guard — same rule as everywhere else.
        $conflicts = app(ServedTowns::class)->conflicts((string) $this->siteId, $entries->all(), $location->id);
        if ($conflicts !== []) {
            $list = collect($conflicts)->map(fn (array $c) => trim($c['town'].' → '.$c['location']))->join('; ');
            Notification::make()->danger()
                ->title('Some towns already belong to another location')
                ->body('Remove them here or from the other location first: '.$list)
                ->send();

            return;
        }

        // Preserve geocodes for towns that were already on the record.
        $existing = collect($location->served_towns ?? [])
            ->keyBy(fn ($t) => mb_strtolower(trim((string) ($t['name'] ?? '')).'|'.trim((string) ($t['state'] ?? ''))));
        $rows = $entries->map(function (string $line) use ($existing) {
            [$name, $state] = array_pad(array_map('trim', explode(',', $line, 2)), 2, '');
            $key = mb_strtolower($name.'|'.$state);

            return $existing->get($key, ['name' => $name, 'state' => $state, 'lat' => null, 'lng' => null, 'geocoded' => false]);
        })->values()->all();

        $location->forceFill([
            'served_towns' => $rows,
            'market_notes' => trim((string) ($this->notes[$locationId] ?? '')) !== '' ? trim((string) $this->notes[$locationId]) : null,
            'is_storefront' => (bool) ($this->storefront[$locationId] ?? false),
        ])->save();

        // Saving IS confirming — seeded fields the operator just reviewed flip to confirmed.
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

        $withTowns = $locations->filter(fn (Location $l) => collect($l->served_towns ?? [])->isNotEmpty())->count();
        if ($withTowns === $locations->count()) {
            return ['state' => 'complete', 'label' => 'Complete'];
        }

        return ['state' => 'attention', 'label' => ($locations->count() - $withTowns).' location(s) missing served towns'];
    }

    private function owned(string $locationId): ?Location
    {
        return $this->siteId === null ? null : Location::withoutGlobalScope(SiteScope::class)
            ->where('site_id', $this->siteId)
            ->whereKey($locationId)
            ->first();
    }
}
