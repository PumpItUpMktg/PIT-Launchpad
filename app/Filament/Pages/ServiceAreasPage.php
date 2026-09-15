<?php

namespace App\Filament\Pages;

use App\Enums\UserRole;
use App\Models\Site;
use App\Operator\ActiveTenant;
use App\TownRank\ServiceAreas;
use BackedEnum;
use Filament\Pages\Page;
use Illuminate\Support\Facades\Auth;
use Livewire\Attributes\Url;

/**
 * Service Areas (§ Town Rank): one page per GBP service area — a physical location and the counties it
 * serves. The list is one card per area; an area opens to one card per tracked keyword with the website's
 * town-rank map and the GBP's map-pack map side by side over the same towns, and a metrics slot for the
 * scoring the operator will define once the data has been seen. Read-only: scans are run from Town Rank
 * (website) and the coverage plans (GBP).
 *
 * @property-read list<array<string, mixed>> $areas
 * @property-read array<string, mixed>|null $area
 */
class ServiceAreasPage extends Page
{
    protected static string|BackedEnum|null $navigationIcon = 'heroicon-o-map';

    protected static ?string $navigationLabel = 'Service areas';

    protected static ?string $title = 'Service Areas';

    protected static string|\UnitEnum|null $navigationGroup = 'Results';

    protected static ?string $slug = 'service-areas';

    protected string $view = 'filament.pages.service-areas';

    public ?string $siteId = null;

    #[Url]
    public ?string $locationId = null;

    public static function menuTag(): string
    {
        return 'unaddressed';
    }

    public static function canAccess(): bool
    {
        return Auth::user()?->role === UserRole::Operator;
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
