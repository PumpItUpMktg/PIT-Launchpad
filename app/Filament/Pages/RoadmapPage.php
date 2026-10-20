<?php

namespace App\Filament\Pages;

use App\Jobs\RefreshGbpProfiles;
use App\Models\Location;
use App\Models\Scopes\SiteScope;
use App\Operator\ActiveTenant;
use App\Roadmap\OfficeRoadmap;
use BackedEnum;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Auth;
use Livewire\Attributes\Url;

/**
 * Roadmap (§ Roadmap) — one page per office: what the map-pack winners have that it does not, with the
 * numbers, as actions. The site index at the top lists every GBP-backed office with its action count and
 * first action; the office below is the one selected. "Refresh profiles" queues the Places pull for the
 * tenant's offices and their pack competitors. Operator-only; the client checklist is a later slice.
 *
 * @property-read array<string, string> $offices
 * @property-read list<array{id: string, name: string, actions: int, first: string|null, in_pack: int, scanned: int}> $index
 * @property-read array<string, mixed>|null $roadmap
 */
class RoadmapPage extends Page
{
    protected static string|BackedEnum|null $navigationIcon = 'heroicon-o-map';

    protected static ?string $navigationLabel = 'Roadmap';

    protected static ?string $title = 'Roadmap';

    protected static string|\UnitEnum|null $navigationGroup = 'Results';

    protected static ?string $slug = 'roadmap';

    protected string $view = 'filament.pages.roadmap';

    public ?string $siteId = null;

    #[Url]
    public ?string $locationId = null;

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
        if ($this->locationId === null || ! array_key_exists($this->locationId, $this->offices)) {
            $this->locationId = array_key_first($this->offices);
        }
    }

    /** @return array<string, string> id => name, the tenant's GBP-backed offices */
    public function getOfficesProperty(): array
    {
        if ($this->siteId === null) {
            return [];
        }

        return Location::withoutGlobalScope(SiteScope::class)->where('site_id', $this->siteId)->gbpBacked()
            ->orderBy('name')->pluck('name', 'id')->map('strval')->all();
    }

    /** @return list<array{id: string, name: string, actions: int, first: string|null, in_pack: int, scanned: int}> */
    public function getIndexProperty(): array
    {
        $rows = [];
        foreach ($this->officeModels() as $office) {
            $r = app(OfficeRoadmap::class)->for($office);
            $rows[] = [
                'id' => (string) $office->id,
                'name' => (string) $office->name,
                'actions' => count($r['actions']),
                'first' => $r['actions'][0]['title'] ?? null,
                'in_pack' => array_sum(array_column($r['keywords'], 'in_pack')),
                'scanned' => array_sum(array_column($r['keywords'], 'scanned')),
            ];
        }

        return $rows;
    }

    /** @return array<string, mixed>|null */
    public function getRoadmapProperty(): ?array
    {
        $office = $this->locationId === null ? null : $this->officeModels()->firstWhere('id', $this->locationId);

        return $office === null ? null : app(OfficeRoadmap::class)->for($office);
    }

    public function refreshProfiles(): void
    {
        if ($this->siteId === null) {
            return;
        }
        RefreshGbpProfiles::dispatch($this->siteId);
        Notification::make()->success()->title('Refreshing Google Business Profiles')
            ->body('One Places call per office and per pack competitor, on the worker. Reload in a minute.')->send();
    }

    /** @return Collection<int, Location> */
    private function officeModels(): Collection
    {
        if ($this->siteId === null) {
            return collect();
        }

        return Location::withoutGlobalScope(SiteScope::class)->where('site_id', $this->siteId)->gbpBacked()->orderBy('name')->get();
    }
}
