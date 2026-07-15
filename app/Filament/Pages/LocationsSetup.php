<?php

namespace App\Filament\Pages;

use App\Locations\Concerns\ManagesLocationCoverage;
use App\Models\Site;
use BackedEnum;
use Filament\Pages\Page;

/**
 * Locations (Settings) — the post-setup locations editor. The whole workspace (base locations,
 * counties served, tiered towns, coverage map) lives in {@see ManagesLocationCoverage}, shared
 * verbatim with the guided WhereYouWork step; this page adds the operator's cross-tenant site
 * picker around it.
 *
 * @property-read array<string, string> $siteOptions
 */
class LocationsSetup extends Page
{
    use ManagesLocationCoverage;

    protected static string|BackedEnum|null $navigationIcon = 'heroicon-o-map-pin';

    protected static ?string $navigationLabel = 'Service area';

    protected static string|\UnitEnum|null $navigationGroup = 'Settings';

    /** Menu-map family tag: setup-world editor (deep-linked from the new Setup steps). */
    public static function menuTag(): string
    {
        return 'setup';
    }

    protected static ?int $navigationSort = 3;

    protected string $view = 'filament.pages.locations-setup';

    public ?string $siteId = null;

    public function updatedSiteId(): void
    {
        $this->reset(['manualLat', 'manualLng', 'computed', 'adding', 'addName', 'addAddress', 'addQuery', 'placeResults', 'activeTab']);
        $this->enterCoverageWorkspace();
    }

    /**
     * @return array<string, string>
     */
    public function getSiteOptionsProperty(): array
    {
        return Site::query()->orderBy('brand_name')->pluck('brand_name', 'id')->all();
    }
}
