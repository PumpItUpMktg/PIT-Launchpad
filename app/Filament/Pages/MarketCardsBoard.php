<?php

namespace App\Filament\Pages;

use App\Enums\UserRole;
use App\Models\Site;
use App\Operate\MarketCard;
use App\Operate\MarketCards;
use App\Operator\ActiveTenant;
use App\Operator\Nav\ConsoleNav;
use BackedEnum;
use Filament\Pages\Page;
use Illuminate\Support\Facades\Auth;

/**
 * Markets (operator) — the wall of market cards: one card per GBP-anchored Location (UI "Market"), ~a
 * dozen per tenant, a ten-thousand-foot "is this market working" answer. Each card rolls up the market's
 * own page plus its town pages (metrics, ranking distribution, size-tier deployment, proof). Clicking a
 * card opens the market detail page (later PRs). Read-only over {@see MarketCards} — one batched pass, no
 * per-card query and no HTTP in the render path (standing rule 2).
 *
 * Tenant-locked to the working {@see ActiveTenant} (no per-page site picker). Operator-only. Wired as
 * Territory → "Markets" in {@see ConsoleNav} (a card per Location is exactly what #790 repointed that
 * label at); the served-town list stays under "Towns" ({@see MarketsBoard}).
 *
 * @property-read list<MarketCard> $cards
 */
class MarketCardsBoard extends Page
{
    protected static string|BackedEnum|null $navigationIcon = 'heroicon-o-map';

    protected static ?string $navigationLabel = 'Markets';

    protected static string|\UnitEnum|null $navigationGroup = 'Territory';

    protected static ?string $slug = 'market-cards';

    protected string $view = 'filament.pages.markets';

    public ?string $siteId = null;

    // The console nav is the explicit ConsoleNav render-hook header, never Filament's auto-sidebar — this
    // page is linked there as Territory → "Markets", so it stays out of the auto-nav like its siblings.
    public static function shouldRegisterNavigation(): bool
    {
        return false;
    }

    public function mount(): void
    {
        $this->siteId = app(ActiveTenant::class)->id();
    }

    public function getTitle(): string
    {
        return 'Markets';
    }

    public function getHeading(): string
    {
        return '';
    }

    public static function canAccess(): bool
    {
        return Auth::user()?->role === UserRole::Operator;
    }

    /** @return list<MarketCard> */
    public function getCardsProperty(): array
    {
        $site = is_string($this->siteId) ? Site::query()->find($this->siteId) : null;

        return $site !== null ? app(MarketCards::class)->forSite($site) : [];
    }
}
