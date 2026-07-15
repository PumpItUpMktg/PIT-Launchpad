<?php

namespace App\Filament\Pages\Operate;

use App\Filament\Pages\LocationsSetup;
use App\Models\Site;
use App\Operate\PhysicalLocations;

/**
 * Operate · Physical locations — one card per base location with the areas it serves. Surfaces
 * the two territory truths at a glance: OVERLAP between locations (flagged per town, naming the
 * other location — the goal state is zero) and the home-county SOFT RULE (a location should
 * serve the county it sits in and its towns — advisory, never enforced). Territory is edited in
 * the Service area workspace; this is the operator's display + audit surface.
 *
 * @property-read array{summary: array<string, int>, cards: list<array<string, mixed>>} $board
 * @property-read array<string, string> $siteOptions
 */
class OperatePhysicalLocations extends OperatePage
{
    protected static ?string $slug = 'operate/locations';

    protected static ?string $navigationLabel = 'Physical locations';

    protected static ?int $navigationSort = 6;

    protected string $view = 'filament.operate.physical-locations';

    public ?string $siteId = null;

    public function mount(): void
    {
        $requested = request()->query('site');
        $candidate = is_string($requested) ? $requested : session('guided_site_id');

        $site = is_string($candidate) ? Site::query()->find($candidate) : null;
        $site ??= Site::query()->orderBy('brand_name')->first();

        if ($site !== null) {
            session(['guided_site_id' => $site->id]);
            $this->siteId = $site->id;
        }
    }

    /** Switch the working site (session-persisted, shared with the rest of Operate). */
    public function setSite(string $siteId): void
    {
        if (Site::query()->whereKey($siteId)->exists()) {
            session(['guided_site_id' => $siteId]);
            $this->siteId = $siteId;
        }
    }

    public function getSite(): ?Site
    {
        return $this->siteId === null ? null : Site::query()->find($this->siteId);
    }

    /** @return array<string, string> */
    public function getSiteOptionsProperty(): array
    {
        return Site::query()->orderBy('brand_name')->pluck('brand_name', 'id')->all();
    }

    /**
     * @return array{summary: array<string, int>, cards: list<array<string, mixed>>}
     */
    public function getBoardProperty(): array
    {
        $site = $this->getSite();

        return $site === null
            ? ['summary' => ['locations' => 0, 'towns_covered' => 0, 'towns_selected' => 0, 'overlaps' => 0], 'cards' => []]
            : app(PhysicalLocations::class)->build($site);
    }

    /** Territory is edited in the Service area workspace — deep link per card. */
    public function serviceAreaUrl(): string
    {
        return LocationsSetup::getUrl();
    }
}
