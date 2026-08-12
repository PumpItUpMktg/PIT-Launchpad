<?php

namespace App\Filament\Console\Pages;

use BackedEnum;

/** Console → Published → Core Pages: the core site pages (Home / Utility / Pillar / Hub / Cluster). */
class PublishedCorePages extends PublishedPage
{
    protected static string|BackedEnum|null $navigationIcon = 'heroicon-o-home-modern';

    protected static ?string $navigationLabel = 'Core Pages';

    protected static ?int $navigationSort = 20;

    protected static ?string $slug = 'published/core';

    protected string $view = 'filament.console.published.core';

    public function getTitle(): string
    {
        return 'Published · Core Pages';
    }

    public function publishedSection(): string
    {
        return 'core';
    }
}
