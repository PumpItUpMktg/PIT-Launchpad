<?php

namespace App\Filament\Pages;

use App\Enums\UserRole;
use App\Operator\ActiveTenant;
use App\Operator\Coverage\RankingStandings;
use BackedEnum;
use Filament\Pages\Page;
use Illuminate\Support\Facades\Auth;

/**
 * Rankings (operator) — the tenant's §7b two-lane position picture: organic movers (up / newly ranked
 * over the tracked window), per-market local-pack standings, and cannibalization flags. Tenant-locked
 * (reads {@see ActiveTenant}, no per-page site picker), operator-only.
 *
 * Read-only and HTTP-free: everything is a persisted read from `position_snapshots` via
 * {@see RankingStandings} — no live SERP/DataForSEO call at render (the provider sits behind the
 * capture path that writes snapshots). Movement is shown as observed, not attributed.
 *
 * @property-read array{summary: array<string, int>, movers: list<array<string, mixed>>, cannibalized: list<array<string, mixed>>, local: list<array<string, mixed>>} $board
 */
class RankingsBoard extends Page
{
    protected static string|BackedEnum|null $navigationIcon = 'heroicon-o-arrow-trending-up';

    protected static ?string $navigationLabel = 'Rankings';

    protected static string|\UnitEnum|null $navigationGroup = 'Results';

    protected static ?string $slug = 'rankings';

    protected string $view = 'filament.pages.rankings-board';

    public ?string $siteId = null;

    public function mount(): void
    {
        $this->siteId = app(ActiveTenant::class)->id();
    }

    public function getTitle(): string
    {
        return 'Rankings';
    }

    public function getHeading(): string
    {
        return '';
    }

    public static function canAccess(): bool
    {
        return Auth::user()?->role === UserRole::Operator;
    }

    /** @return array{summary: array<string, int>, movers: list<array<string, mixed>>, cannibalized: list<array<string, mixed>>, local: list<array<string, mixed>>} */
    public function getBoardProperty(): array
    {
        return app(RankingStandings::class)->for($this->siteId);
    }
}
