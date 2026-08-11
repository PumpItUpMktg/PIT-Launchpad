<?php

namespace App\Filament\Console\Pages;

use BackedEnum;

/** Console → Published → Service Pages: the live service pages (page_type=service). */
class PublishedServicePages extends PublishedPage
{
    protected static string|BackedEnum|null $navigationIcon = 'heroicon-o-wrench-screwdriver';

    protected static ?string $navigationLabel = 'Service Pages';

    protected static ?int $navigationSort = 30;

    protected static ?string $slug = 'published/service';

    protected string $view = 'filament.console.published.service';

    public function getTitle(): string
    {
        return 'Published · Service Pages';
    }
}
