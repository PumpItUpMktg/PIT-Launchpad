<?php

namespace App\Filament\Console\Pages;

use BackedEnum;

/**
 * Console → Published → Location Pages: the town/city pages nested under each storefront
 * (page_type=location with `parent_location_id` pinned), one sub-tab per storefront.
 */
class PublishedLocationPages extends PublishedPage
{
    protected static string|BackedEnum|null $navigationIcon = 'heroicon-o-map-pin';

    protected static ?string $navigationLabel = 'Location Pages';

    protected static ?int $navigationSort = 50;

    protected static ?string $slug = 'published/location';

    protected string $view = 'filament.console.published.location';

    /** Which storefront's town pages are showing (base-location id). */
    public ?string $activeStorefront = null;

    public function getTitle(): string
    {
        return 'Published · Location Pages';
    }

    /** Clear the storefront sub-tab selection when the tenant changes (it belongs to one site). */
    public function updatedSiteId(): void
    {
        parent::updatedSiteId();
        $this->activeStorefront = null;
    }
}
