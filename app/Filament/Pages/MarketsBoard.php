<?php

namespace App\Filament\Pages;

use App\Enums\UserRole;
use App\Models\Market;
use App\Models\Scopes\SiteScope;
use App\Operator\ActiveTenant;
use App\Operator\Coverage\MarketHold;
use App\Operator\Coverage\MarketPortfolio;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\Select;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Auth;

/**
 * Markets (operator) — the tenant's targetable geo subjects in one place: tier (Priority vs Coverage),
 * coverage flag, demographics, and the downstream targeting weight (location pages + keywords pinned to
 * each market), plus the advisory **hold** control. A hold is a reminder only — no publish effect — and
 * a held market whose target release date has passed surfaces as overdue (the same signal the operator
 * lobby flags). This page is the in-panel equivalent of the `launchpad:market-hold` command; both route
 * through {@see MarketHold} so the semantics live in one place.
 *
 * Tenant-locked: reads the working tenant from {@see ActiveTenant} (no per-page site picker). Operator-only.
 *
 * @property-read array{markets: list<array<string, mixed>>, summary: array<string, int>} $board
 */
class MarketsBoard extends Page
{
    protected static string|BackedEnum|null $navigationIcon = 'heroicon-o-globe-americas';

    protected static ?string $navigationLabel = 'Markets';

    protected static string|\UnitEnum|null $navigationGroup = 'Territory';

    protected static ?string $slug = 'markets';

    protected string $view = 'filament.pages.markets-board';

    public ?string $siteId = null;

    public function mount(): void
    {
        $this->siteId = app(ActiveTenant::class)->id();
    }

    public function getTitle(): string
    {
        return 'Markets';
    }

    // The shared lp header (with the tenant-scope indicator) is the visible heading; suppress Filament's
    // own duplicate h1 so only the "Place a hold" action renders in the Filament header strip.
    public function getHeading(): string
    {
        return '';
    }

    public static function canAccess(): bool
    {
        return Auth::user()?->role === UserRole::Operator;
    }

    /** @return array{markets: list<array<string, mixed>>, summary: array<string, int>} */
    public function getBoardProperty(): array
    {
        return app(MarketPortfolio::class)->for($this->siteId);
    }

    protected function getHeaderActions(): array
    {
        return [$this->placeHoldAction()];
    }

    /**
     * Place an advisory hold on a market with a target release date. Header-level (Filament's demonstrated
     * modal pattern): pick an un-held market + a date; routed through {@see MarketHold::hold}. Hidden when
     * every market is already held (nothing to pick).
     */
    protected function placeHoldAction(): Action
    {
        return Action::make('placeHold')
            ->label('Place a hold')
            ->icon('heroicon-o-pause-circle')
            ->visible(fn (): bool => $this->siteId !== null && $this->unheldOptions() !== [])
            ->schema([
                Select::make('market')
                    ->label('Market')
                    ->options(fn (): array => $this->unheldOptions())
                    ->searchable()
                    ->required(),
                DatePicker::make('release_at')
                    ->label('Target release date')
                    ->helperText('A reminder only — a hold has no publish effect. The market shows as overdue once this date passes.')
                    ->native(false)
                    ->minDate(today())
                    ->required(),
            ])
            ->action(function (array $data): void {
                $market = $this->findMarket((string) $data['market']);
                if ($market === null) {
                    return;
                }

                app(MarketHold::class)->hold($market, Carbon::parse((string) $data['release_at']));

                Notification::make()
                    ->title("Held {$market->name} until ".Carbon::parse((string) $data['release_at'])->toFormattedDateString())
                    ->success()
                    ->send();
            });
    }

    /** Lift a hold — the per-row action on a held market. */
    public function release(string $marketId): void
    {
        $market = $this->findMarket($marketId);
        if ($market === null) {
            return;
        }

        app(MarketHold::class)->release($market);

        Notification::make()->title("Released the hold on {$market->name}")->success()->send();
    }

    /**
     * Un-held markets, name-ordered, as `[id => "Name · REGION"]` for the hold picker.
     *
     * @return array<string, string>
     */
    private function unheldOptions(): array
    {
        if ($this->siteId === null) {
            return [];
        }

        return Market::withoutGlobalScope(SiteScope::class)
            ->where('site_id', $this->siteId)
            ->where('on_hold', false)
            ->orderBy('name')
            ->get(['id', 'name', 'region'])
            ->mapWithKeys(fn (Market $m): array => [
                (string) $m->id => trim(((string) $m->name).($m->region !== null ? ' · '.$m->region : '')),
            ])
            ->all();
    }

    /** Resolve a market within the locked tenant (never cross-tenant). */
    private function findMarket(string $marketId): ?Market
    {
        if ($this->siteId === null) {
            return null;
        }

        return Market::withoutGlobalScope(SiteScope::class)
            ->where('site_id', $this->siteId)
            ->whereKey($marketId)
            ->first();
    }
}
