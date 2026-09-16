<?php

namespace App\Filament\Pages;

use App\Enums\UserRole;
use App\GeoGrid\GeoGridBoard;
use App\GeoGrid\TownAreaMap;
use App\Models\GeoGridScan;
use App\Models\Location;
use App\Models\Scopes\SiteScope;
use App\Operator\ActiveTenant;
use BackedEnum;
use Filament\Pages\Page;
use Illuminate\Support\Facades\Auth;
use Livewire\Attributes\Url;

/**
 * Geo Grid (operator) — the map-pack board for one GBP-backed location: a card wall, one card per keyword,
 * each a map of the towns this location serves, coloured by the position the profile holds in that town's
 * map pack, with the position written into the town. Click a card to expand it: the same map at full size,
 * every town listed best-first, and the competitors seen across them. The point is reading every keyword's
 * local health at a glance, so it is deliberately NOT a keyword dropdown.
 *
 * The geography is the real one — county outline, each town's own boundary, the same projector the website's
 * town-rank map uses ({@see TownAreaMap}) — so a town sits on the same spot on both maps and
 * the two can be read side by side. Scans are coverage mode: one Maps search per town from that town's own
 * coordinates, which replaced the 7×7 lattice (a square of points that answered for no town in particular).
 *
 * Operator-only, internal test build (§ Geo Grid PR 6) — gated strictly to {@see UserRole::Operator} like the
 * sibling GEO consoles, not merely the admin panel's staff default. All assembly is delegated to the testable
 * {@see GeoGridBoard} read-model; this page is the thin tenant → location selector around it. siteId/locationId
 * are URL-bound so the per-location dashboard (PR 7) can deep-link straight to a location's grid board.
 *
 * @property-read array<string, string> $sites
 * @property-read array<string, string> $locations
 * @property-read array<string, mixed>|null $board
 * @property-read Location|null $location
 */
class LocationGeoGrid extends Page
{
    protected static string|BackedEnum|null $navigationIcon = 'heroicon-o-map';

    protected static ?string $navigationLabel = 'Geo Grid';

    protected static string|\UnitEnum|null $navigationGroup = 'Settings';

    protected static ?int $navigationSort = 8;

    protected static ?string $slug = 'geo-grid';

    protected string $view = 'filament.pages.location-geo-grid';

    public ?string $siteId = null;

    #[Url]
    public ?string $locationId = null;

    /** Internal test surface — its final IA placement is still open. */
    public static function menuTag(): string
    {
        return 'unaddressed';
    }

    /** Operator-only: this is an internal, uncalibrated test build — not for admins or clients. */
    public static function canAccess(): bool
    {
        return Auth::user()?->role === UserRole::Operator;
    }

    public function mount(): void
    {
        $this->siteId = app(ActiveTenant::class)->id();
        if ($this->locationId === null) {
            $this->locationId = $this->firstScannedLocationId() ?? array_key_first($this->locations);
        }
    }

    /** Switching tenant clears the location focus — the prior tenant's locations aren't the new one's. */

    /** @return array<string, string> */

    /**
     * The tenant's GBP-backed locations (the dashboard-eligible set) for the selector.
     *
     * @return array<string, string>
     */
    public function getLocationsProperty(): array
    {
        if ($this->siteId === null) {
            return [];
        }

        return Location::withoutGlobalScope(SiteScope::class)
            ->where('site_id', $this->siteId)->gbpBacked()
            ->orderBy('name')
            ->pluck('name', 'id')
            ->map(fn ($name, $id): string => (string) ($name ?: $id))
            ->all();
    }

    public function getLocationProperty(): ?Location
    {
        if ($this->locationId === null) {
            return null;
        }

        return Location::withoutGlobalScope(SiteScope::class)
            ->where('site_id', $this->siteId)
            ->whereKey($this->locationId)
            ->first();
    }

    /** @return array<string, mixed>|null */
    public function getBoardProperty(): ?array
    {
        $location = $this->location;

        return $location !== null ? app(GeoGridBoard::class)->for($location) : null;
    }

    /**
     * The hover title for one expanded grid cell — rank (or not-found), coordinate, and its top-3
     * competitors at that point. Kept on the page (not the view) so the string-building stays testable-ish
     * and out of the Blade.
     *
     * @param  array<string, mixed>  $town
     */
    public function townTitle(array $town): string
    {
        $rank = $town['rank'] ?? null;
        $parts = [(string) ($town['label'] ?? '—')];
        $parts[] = $rank !== null ? "map pack #{$rank}" : (($town['pending'] ?? false) ? 'still collecting' : 'absent from the pack');
        if (($town['move'] ?? null) !== null && $town['move'] !== 0) {
            $parts[] = $town['move'] > 0 ? "up {$town['move']}" : 'down '.abs((int) $town['move']);
        }

        $comps = array_filter(array_map(
            fn (array $c): string => trim(($c['name'] ?? '').(isset($c['rank']) ? " (#{$c['rank']})" : '')),
            is_array($town['competitors'] ?? null) ? $town['competitors'] : []
        ));
        if ($comps !== []) {
            $parts[] = 'vs '.implode(', ', array_slice($comps, 0, 3));
        }

        return implode(' · ', $parts);
    }

    /**
     * Aggregate the top competitors across every town of a card — how often each shows up and its best rank
     * seen — for the expanded card's competitor panel.
     *
     * @param  array<string, mixed>  $card
     * @return list<array{name: string, points: int, best: int}>
     */
    public function topCompetitors(array $card): array
    {
        $tally = [];
        foreach ($card['towns'] as $cell) {

            foreach ((is_array($cell['competitors'] ?? null) ? $cell['competitors'] : []) as $c) {
                $name = trim((string) ($c['name'] ?? ''));
                $rank = $c['rank'] ?? null;
                if ($name === '' || $rank === null) {
                    continue;
                }
                $tally[$name] ??= ['name' => $name, 'points' => 0, 'best' => PHP_INT_MAX];
                $tally[$name]['points']++;
                $tally[$name]['best'] = min($tally[$name]['best'], (int) $rank);
            }

        }

        $ranked = array_values($tally);
        usort($ranked, fn (array $a, array $b): int => $b['points'] <=> $a['points'] ?: $a['best'] <=> $b['best']);

        return array_slice($ranked, 0, 8);
    }

    /** The tenant's first location that already has a scan, so the board opens on real data. */
    private function firstScannedLocationId(): ?string
    {
        if ($this->siteId === null) {
            return null;
        }

        return GeoGridScan::withoutGlobalScope(SiteScope::class)
            ->where('site_id', $this->siteId)
            ->orderByDesc('scanned_at')
            ->value('location_id');
    }
}
