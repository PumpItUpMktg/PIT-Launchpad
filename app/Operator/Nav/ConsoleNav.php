<?php

namespace App\Operator\Nav;

use App\Filament\Pages\Citations\CitationsPortfolio;
use App\Filament\Pages\Gathering\SetupEntry;
use App\Filament\Pages\GeoActivityConsole;
use App\Filament\Pages\Live\LiveServices;
use App\Filament\Pages\LocationCoverage;
use App\Filament\Pages\LocationGeoGrid;
use App\Filament\Pages\LocationsSetup;
use App\Filament\Pages\Operate\InternalLinks;
use App\Filament\Pages\Operate\OperateBlog;
use App\Filament\Pages\Operate\OperateCorePages;
use App\Filament\Pages\Operate\RebuildReadiness;
use App\Filament\Pages\Operate\TenantDashboard;
use App\Filament\Resources\ConnectionsResource;
use App\Filament\Resources\KeywordResource;
use App\Filament\Resources\ReviewCaptureResource;
use App\Filament\Resources\SiloManagementResource;
use App\Filament\Resources\SourceResource;
use App\Filament\Resources\VoiceProfileResource;

/**
 * The operator console navigation — the single source of the header IA (Relay 3 · PR 5). A
 * **four-column header**: four groups (Build · Territory · Results · System), no dropdowns, exactly
 * 24 items. Every item is either a live link to its surface or a "soon" placeholder for a surface
 * that has not shipped yet — the IA is complete and legible from day one; each placeholder goes live
 * as its own PR lands (see `docs/specs/5-nav-cutover.md`, the authoritative mapping).
 *
 * The panel renders this via a render hook, not Filament's auto-registered sidebar — the auto nav is
 * replaced by an explicit four-column header. The Lobby is the cross-tenant home and lives OUTSIDE
 * the panel (the 2b decision); it is not a nav item here. Portfolio and Overview retire into the
 * Lobby and are not items either.
 *
 * `columns()` resolves each surface's URL at call time (panel/route context), so it must run inside a
 * request. `structure()` is the URL-free shape for tests and reasoning.
 */
class ConsoleNav
{
    /**
     * The IA shape, independent of routing: four groups, each a list of items. `soon` marks a
     * placeholder (no admin surface yet); `surface` is the page/resource class (null for a gap).
     *
     * @return list<array{group: string, items: list<array{label: string, surface: ?class-string, soon: bool}>}>
     */
    public function structure(): array
    {
        return [
            ['group' => 'Build', 'items' => [
                ['label' => 'Dashboard', 'surface' => TenantDashboard::class, 'soon' => false],
                ['label' => 'Setup', 'surface' => SetupEntry::class, 'soon' => false],
                ['label' => 'Posts', 'surface' => OperateBlog::class, 'soon' => false],
                ['label' => 'Pages', 'surface' => OperateCorePages::class, 'soon' => false],
                ['label' => 'Jobs', 'surface' => null, 'soon' => true],
                ['label' => 'Reviews', 'surface' => ReviewCaptureResource::class, 'soon' => false],
                ['label' => 'Live', 'surface' => LiveServices::class, 'soon' => false],
            ]],
            ['group' => 'Territory', 'items' => [
                ['label' => 'Markets', 'surface' => null, 'soon' => true],
                ['label' => 'Towns', 'surface' => LocationsSetup::class, 'soon' => false],
                ['label' => 'Citations', 'surface' => CitationsPortfolio::class, 'soon' => false],
                ['label' => 'Silos', 'surface' => SiloManagementResource::class, 'soon' => false],
                ['label' => 'Keywords', 'surface' => KeywordResource::class, 'soon' => false],
                ['label' => 'Internal links', 'surface' => InternalLinks::class, 'soon' => false],
            ]],
            ['group' => 'Results', 'items' => [
                ['label' => 'Rankings', 'surface' => null, 'soon' => true],
                ['label' => 'Indexing', 'surface' => null, 'soon' => true],
                ['label' => 'Geo grid', 'surface' => LocationGeoGrid::class, 'soon' => false],
                ['label' => 'Coverage', 'surface' => LocationCoverage::class, 'soon' => false],
                ['label' => 'AI visibility', 'surface' => GeoActivityConsole::class, 'soon' => false],
            ]],
            ['group' => 'System', 'items' => [
                ['label' => 'Connections', 'surface' => ConnectionsResource::class, 'soon' => false],
                ['label' => 'Feeds', 'surface' => SourceResource::class, 'soon' => false],
                ['label' => 'Brand', 'surface' => null, 'soon' => true],
                ['label' => 'Voice', 'surface' => VoiceProfileResource::class, 'soon' => false],
                ['label' => 'Users', 'surface' => null, 'soon' => true],
                ['label' => 'Recover', 'surface' => RebuildReadiness::class, 'soon' => false],
            ]],
        ];
    }

    /**
     * The render-ready columns: the structure with each surface resolved to its URL. A `soon` item
     * has a null URL (rendered greyed + non-clickable). Must run inside a request (Filament panel
     * context) so `::getUrl()` can resolve.
     *
     * @return list<array{group: string, items: list<array{label: string, url: ?string, soon: bool}>}>
     */
    public function columns(): array
    {
        return array_map(fn (array $col): array => [
            'group' => $col['group'],
            'items' => array_map(fn (array $item): array => [
                'label' => $item['label'],
                'url' => $item['soon'] || $item['surface'] === null ? null : $item['surface']::getUrl(),
                'soon' => $item['soon'],
            ], $col['items']),
        ], $this->structure());
    }
}
