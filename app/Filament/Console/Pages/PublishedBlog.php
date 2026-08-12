<?php

namespace App\Filament\Console\Pages;

use BackedEnum;

/** Console → Published → Blog: the live blog posts (kind=post), each as a rich card. */
class PublishedBlog extends PublishedPage
{
    protected static string|BackedEnum|null $navigationIcon = 'heroicon-o-newspaper';

    protected static ?string $navigationLabel = 'Blog';

    protected static ?int $navigationSort = 10;

    protected static ?string $slug = 'published/blog';

    protected string $view = 'filament.console.published.blog';

    public function getTitle(): string
    {
        return 'Published · Blog';
    }

    public function publishedSection(): string
    {
        return 'blog';
    }

    /** The blog page offers the brick-and-mortar town filter (posts carry copy to scan). */
    public function supportsTownFilter(): bool
    {
        return true;
    }
}
