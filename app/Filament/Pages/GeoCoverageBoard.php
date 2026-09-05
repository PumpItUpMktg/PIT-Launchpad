<?php

namespace App\Filament\Pages;

use App\Geo\GeoCoverage;
use App\Geo\GeoCoveragePromptSeeder;
use App\Geo\GeoCoverageVerification;
use App\Geo\GeoPromptSeeder;
use App\Models\Location;
use App\Models\Scopes\SiteScope;
use App\Models\Site;
use App\Operator\ActiveTenant;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Notifications\Notification;
use Filament\Pages\Page;

/**
 * AI Coverage (GEO) — the operator's "where are we weak" view: a services × markets matrix (green =
 * cited, red = absent, blank = never asked) plus a ranked gap list, per tenant. Reads the dimension-tagged
 * prompts + multi-engine snapshots via {@see GeoCoverage}. Operator-only (admin panel); tagged
 * `unaddressed` in the menu-map like the rest of GEO Phase 1.
 *
 * @property-read array<string, mixed>|null $report
 * @property-read array<string, string> $sites
 */
class GeoCoverageBoard extends Page
{
    protected static string|BackedEnum|null $navigationIcon = 'heroicon-o-table-cells';

    protected static ?string $navigationLabel = 'AI Coverage (GEO)';

    protected static string|\UnitEnum|null $navigationGroup = 'Settings';

    protected static ?int $navigationSort = 7;

    protected static ?string $slug = 'geo-coverage';

    protected string $view = 'filament.pages.geo-coverage';

    /** Selected tenant (operator picks). */
    public ?string $siteId = null;

    /** Selected brick-and-mortar shop — null = all of the tenant's shops. */
    public ?string $locationId = null;

    /** Menu-map family tag: a Phase-1 operator surface whose final placement is still to be decided. */
    public static function menuTag(): string
    {
        return 'unaddressed';
    }

    /** Switching tenant clears the shop focus — the prior tenant's locations don't belong to the new one. */
    /**
     * Seed GEO prompts for the SELECTED area only — the operator picks a tenant + brick-and-mortar shop
     * above, and seeding scopes to that shop's towns (so you target one area at a time instead of the
     * whole footprint). Seeding is DB-only (no LLM), so it runs inline. "All shops" seeds every town.
     *
     * @return array<Action>
     */
    protected function getHeaderActions(): array
    {
        return [
            Action::make('seedVisibility')
                ->label('Seed visibility prompts')
                ->icon('heroicon-o-sparkles')
                ->visible(fn (): bool => $this->siteId !== null)
                ->requiresConfirmation()
                ->modalDescription(fn (): string => 'Seed neutral "best {service} in {town}" prompts for '.$this->scopeLabel().' (published towns, biggest first). Bounded + idempotent.')
                ->action(fn () => $this->runSeed(app(GeoPromptSeeder::class))),
            Action::make('seedCoverage')
                ->label('Seed coverage checks')
                ->icon('heroicon-o-shield-check')
                ->visible(fn (): bool => $this->siteId !== null)
                ->requiresConfirmation()
                ->modalDescription(fn (): string => 'Seed brand-anchored "does {brand} serve {town}?" accuracy checks for '.$this->scopeLabel().'. Bounded + idempotent.')
                ->action(fn () => $this->runSeed(app(GeoCoveragePromptSeeder::class))),
        ];
    }

    private function runSeed(GeoPromptSeeder|GeoCoveragePromptSeeder $seeder): void
    {
        $site = $this->siteId !== null ? Site::query()->whereKey($this->siteId)->first() : null;
        if ($site === null) {
            return;
        }

        $r = $seeder->seed($site, $this->locationId ?: null);

        Notification::make()
            ->title($r['created'] > 0 ? "Seeded {$r['created']} prompt(s) for ".$this->scopeLabel() : 'No new prompts to seed for '.$this->scopeLabel())
            ->success()
            ->send();
    }

    /** A human label for the current tenant + shop scope, for the seed confirmations. */
    private function scopeLabel(): string
    {
        return $this->locationId !== null ? ($this->locations[$this->locationId] ?? 'this shop') : 'all shops';
    }

    public function mount(): void
    {
        $this->siteId = app(ActiveTenant::class)->id();
    }

    /**
     * The selected tenant's brick-and-mortar shops (excludes NAP-merged rows), for the shop selector.
     *
     * @return array<string, string>
     */
    public function getLocationsProperty(): array
    {
        if ($this->siteId === null) {
            return [];
        }

        return Location::withoutGlobalScope(SiteScope::class)
            ->where('site_id', $this->siteId)->whereNull('merged_into_id')
            ->orderBy('name')
            ->pluck('name', 'id')
            ->map(fn ($name, $id): string => (string) ($name ?: $id))
            ->all();
    }

    /** @return array<string, mixed>|null */
    public function getReportProperty(): ?array
    {
        if ($this->siteId === null) {
            return null;
        }
        $site = Site::query()->whereKey($this->siteId)->first();

        return $site !== null ? app(GeoCoverage::class)->report($site, $this->locationId ?: null) : null;
    }

    /**
     * The coverage-check accuracy view — "does the AI know this shop serves these towns?" — reported apart
     * from the visibility matrix. Null when no tenant is selected or the tenant has no coverage prompts.
     *
     * @return array<string, mixed>|null
     */
    public function getVerificationProperty(): ?array
    {
        if ($this->siteId === null) {
            return null;
        }
        $site = Site::query()->whereKey($this->siteId)->first();
        if ($site === null) {
            return null;
        }

        $report = app(GeoCoverageVerification::class)->report($site, $this->locationId ?: null);

        return $report['total'] > 0 ? $report : null;
    }
}
