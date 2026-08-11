<?php

namespace App\Filament\Console\Pages;

use BackedEnum;

/**
 * Console → Published → Storefront Pages: the base-location hub pages — a brick-and-mortar / GMB
 * location acting as the hub for its town pages (page_type=location with `location_id` pinned).
 */
class PublishedStorefrontPages extends PublishedPage
{
    protected static string|BackedEnum|null $navigationIcon = 'heroicon-o-building-storefront';

    protected static ?string $navigationLabel = 'Storefront Pages';

    protected static ?int $navigationSort = 40;

    protected static ?string $slug = 'published/storefront';

    protected string $view = 'filament.console.published.storefront';

    public function getTitle(): string
    {
        return 'Published · Storefront Pages';
    }
}
