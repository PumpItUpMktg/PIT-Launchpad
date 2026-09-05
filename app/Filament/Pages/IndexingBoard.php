<?php

namespace App\Filament\Pages;

use App\Enums\UserRole;
use App\Operator\ActiveTenant;
use App\Operator\Coverage\IndexStandings;
use BackedEnum;
use Filament\Pages\Page;
use Illuminate\Support\Facades\Auth;

/**
 * Indexing (operator) — Google index coverage for the tenant, split two ways that keep the number
 * honest: the pages Launchpad **published** (in our sitemap) vs **all** URLs Google knows about
 * (including WP archives it merely found), each with a per-reason breakdown of what isn't indexed.
 *
 * Tenant-locked (reads {@see ActiveTenant}, no per-page site picker), operator-only. Read-only and
 * HTTP-free: everything is a persisted read from `page_index_states` via {@see IndexStandings} — no
 * live GSC / URL-Inspection call at render (that provider sits behind the `sandhog:sync-index` capture).
 *
 * @property-read array{published: array<string, mixed>, all_known: array<string, mixed>, discovered_only: int} $board
 */
class IndexingBoard extends Page
{
    protected static string|BackedEnum|null $navigationIcon = 'heroicon-o-magnifying-glass-circle';

    protected static ?string $navigationLabel = 'Indexing';

    protected static string|\UnitEnum|null $navigationGroup = 'Results';

    protected static ?string $slug = 'indexing';

    protected string $view = 'filament.pages.indexing-board';

    public ?string $siteId = null;

    public function mount(): void
    {
        $this->siteId = app(ActiveTenant::class)->id();
    }

    public function getTitle(): string
    {
        return 'Indexing';
    }

    public function getHeading(): string
    {
        return '';
    }

    public static function canAccess(): bool
    {
        return Auth::user()?->role === UserRole::Operator;
    }

    /** @return array{published: array<string, mixed>, all_known: array<string, mixed>, discovered_only: int} */
    public function getBoardProperty(): array
    {
        return app(IndexStandings::class)->for($this->siteId);
    }
}
