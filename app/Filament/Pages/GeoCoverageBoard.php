<?php

namespace App\Filament\Pages;

use App\Geo\GeoCoverage;
use App\Models\GeoPrompt;
use App\Models\Location;
use App\Models\Scopes\SiteScope;
use App\Models\Site;
use BackedEnum;
use Filament\Pages\Page;

/**
 * AI Coverage (GEO) — the operator's "where are we weak" view: a services × markets matrix (green =
 * cited, red = absent, blank = never asked) plus a ranked gap list, per tenant. Reads the dimension-tagged
 * prompts + multi-engine snapshots via {@see GeoCoverage}. Operator-only (admin panel); tagged
 * `unaddressed` in the menu-map like the rest of GEO Phase 1.
 *
 * @property-read array<string, mixed>|null $report
 * @property-read array<string, string> $sites
 */
class GeoCoverageBoard extends Page
{
    protected static string|BackedEnum|null $navigationIcon = 'heroicon-o-table-cells';

    protected static ?string $navigationLabel = 'AI Coverage (GEO)';

    protected static string|\UnitEnum|null $navigationGroup = 'Settings';

    protected static ?int $navigationSort = 7;

    protected static ?string $slug = 'geo-coverage';

    protected string $view = 'filament.pages.geo-coverage';

    /** Selected tenant (operator picks). */
    public ?string $siteId = null;

    /** Selected brick-and-mortar shop — null = all of the tenant's shops. */
    public ?string $locationId = null;

    /** Menu-map family tag: a Phase-1 operator surface whose final placement is still to be decided. */
    public static function menuTag(): string
    {
        return 'unaddressed';
    }

    /** Switching tenant clears the shop focus — the prior tenant's locations don't belong to the new one. */
    public function updatedSiteId(): void
    {
        $this->locationId = null;
    }

    public function mount(): void
    {
        // Default to a tenant that already has GEO prompts, else the first tenant.
        $this->siteId = (string) (GeoPrompt::query()->distinct()->value('site_id')
            ?? Site::query()->orderBy('brand_name')->value('id'))
            ?: null;
    }

    /** @return array<string, string> */
    public function getSitesProperty(): array
    {
        return Site::query()->orderBy('brand_name')
            ->pluck('brand_name', 'id')
            ->map(fn ($name, $id): string => (string) ($name ?: $id))
            ->all();
    }

    /**
     * The selected tenant's brick-and-mortar shops (excludes NAP-merged rows), for the shop selector.
     *
     * @return array<string, string>
     */
    public function getLocationsProperty(): array
    {
        if ($this->siteId === null) {
            return [];
        }

        return Location::withoutGlobalScope(SiteScope::class)
            ->where('site_id', $this->siteId)->whereNull('merged_into_id')
            ->orderBy('name')
            ->pluck('name', 'id')
            ->map(fn ($name, $id): string => (string) ($name ?: $id))
            ->all();
    }

    /** @return array<string, mixed>|null */
    public function getReportProperty(): ?array
    {
        if ($this->siteId === null) {
            return null;
        }
        $site = Site::query()->whereKey($this->siteId)->first();

        return $site !== null ? app(GeoCoverage::class)->report($site, $this->locationId ?: null) : null;
    }
}
