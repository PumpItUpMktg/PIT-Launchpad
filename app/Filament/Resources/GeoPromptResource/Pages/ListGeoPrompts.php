<?php

namespace App\Filament\Resources\GeoPromptResource\Pages;

use App\Filament\Resources\GeoPromptResource;
use App\Filament\Widgets\GeoCheckActivityWidget;
use App\Filament\Widgets\GeoCheckStatusWidget;
use App\Geo\GeoCoveragePromptSeeder;
use App\Jobs\BridgeSiteGeoGaps;
use App\Jobs\SeedSiteGeoPrompts;
use App\Jobs\SyncSiteGeo;
use App\Jobs\TopUpSiteGeoPrompts;
use App\Models\GeoPrompt;
use App\Models\GeoSnapshot;
use App\Models\Scopes\SiteScope;
use App\Models\Service;
use App\Models\Site;
use App\Operator\ActiveTenant;
use Filament\Actions\Action;
use Filament\Actions\CreateAction;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\ListRecords;
use Filament\Widgets\Widget;

class ListGeoPrompts extends ListRecords
{
    protected static string $resource = GeoPromptResource::class;

    /**
     * @return array<class-string<Widget>>
     */
    protected function getHeaderWidgets(): array
    {
        return [
            GeoCheckStatusWidget::class,
            GeoCheckActivityWidget::class,
        ];
    }

    /**
     * The working tenant — the {@see ActiveTenant} lock (tenant-lock remediation). Every header action
     * scopes to it so a GEO run/seed/bridge fires only for the locked tenant, never fanning out across the
     * portfolio. The old all-tenant "Tenant" filter (and its "All" mode) is gone; changing tenant is
     * Exit site → Lobby → enter.
     */
    private function selectedTenantId(): ?string
    {
        return app(ActiveTenant::class)->id();
    }

    /** Human label for the scope, for confirmation copy and notifications. */
    private function scopeLabel(?string $tenantId): string
    {
        if ($tenantId === null) {
            return 'every tenant with matching prompts';
        }

        $name = Site::query()->withoutGlobalScope(SiteScope::class)->whereKey($tenantId)->value('brand_name');

        return is_string($name) && $name !== '' ? $name : 'the selected tenant';
    }

    /**
     * @return array<Action>
     */
    protected function getHeaderActions(): array
    {
        return [
            Action::make('seed')
                ->label('Auto-seed prompts')
                ->icon('heroicon-o-square-3-stack-3d')
                ->requiresConfirmation()
                ->modalDescription(fn (): string => 'Generate GEO prompts from services × priority markets × intents for '.$this->scopeLabel($this->selectedTenantId()).' (bounded + idempotent — re-running only adds what\'s new).')
                ->action(function (): void {
                    $tenantId = $this->selectedTenantId();
                    $siteIds = Service::query()
                        ->when($tenantId !== null, fn ($q) => $q->where('site_id', $tenantId))
                        ->distinct()->pluck('site_id');
                    foreach ($siteIds as $siteId) {
                        SeedSiteGeoPrompts::dispatch((string) $siteId);
                    }
                    Notification::make()
                        ->title($siteIds->isEmpty() ? 'No sites with services to seed' : "Seeding queued for {$siteIds->count()} site(s)")
                        ->success()
                        ->send();
                }),
            Action::make('seedCoverage')
                ->label('Seed coverage checks')
                ->icon('heroicon-o-shield-check')
                ->requiresConfirmation()
                ->modalDescription(fn (): string => 'Generate brand-anchored "does {brand} offer {service} in {town}?" prompts (published towns) for '.$this->scopeLabel($this->selectedTenantId()).'. These are an accuracy check — reported apart from the visibility score — to catch when an AI has your service area wrong. Bounded + idempotent.')
                ->action(function (): void {
                    $tenantId = $this->selectedTenantId();
                    $seeder = app(GeoCoveragePromptSeeder::class);
                    $created = 0;
                    $siteIds = Service::query()
                        ->when($tenantId !== null, fn ($q) => $q->where('site_id', $tenantId))
                        ->distinct()->pluck('site_id');
                    foreach ($siteIds as $siteId) {
                        $site = Site::query()->withoutGlobalScope(SiteScope::class)->find($siteId);
                        if ($site !== null) {
                            $created += $seeder->seed($site)['created'];
                        }
                    }
                    Notification::make()
                        ->title($created > 0 ? "Seeded {$created} coverage-check prompt(s)" : 'No new coverage checks to seed')
                        ->success()
                        ->send();
                }),
            Action::make('topup')
                ->label('Generate top-ups')
                ->icon('heroicon-o-sparkles')
                ->requiresConfirmation()
                ->modalDescription(fn (): string => 'Use AI to add prompt variants for the absent gaps (prompts no engine cites) for '.$this->scopeLabel($this->selectedTenantId()).' — neutral rephrasings + head-to-heads. Bounded; the variants land tagged "assisted" and active.')
                ->action(function (): void {
                    $tenantId = $this->selectedTenantId();
                    $siteIds = GeoSnapshot::query()
                        ->when($tenantId !== null, fn ($q) => $q->where('site_id', $tenantId))
                        ->distinct()->pluck('site_id');
                    foreach ($siteIds as $siteId) {
                        TopUpSiteGeoPrompts::dispatch((string) $siteId);
                    }
                    Notification::make()
                        ->title($siteIds->isEmpty() ? 'No measured sites to top up yet' : "Top-ups queued for {$siteIds->count()} site(s)")
                        ->success()
                        ->send();
                }),
            Action::make('bridge')
                ->label('Bridge gaps to content')
                ->icon('heroicon-o-arrow-right-circle')
                ->requiresConfirmation()
                ->modalDescription(fn (): string => 'Turn each absent gap (a prompt no engine cites) for '.$this->scopeLabel($this->selectedTenantId()).' into a directed content candidate on the review queue — pinned to the gap\'s service silo, ready to generate & publish. Bounded + idempotent; nothing is drafted or published automatically.')
                ->action(function (): void {
                    $tenantId = $this->selectedTenantId();
                    $siteIds = GeoSnapshot::query()
                        ->when($tenantId !== null, fn ($q) => $q->where('site_id', $tenantId))
                        ->distinct()->pluck('site_id');
                    foreach ($siteIds as $siteId) {
                        BridgeSiteGeoGaps::dispatch((string) $siteId);
                    }
                    Notification::make()
                        ->title($siteIds->isEmpty() ? 'No measured sites to bridge yet' : "Gap bridge queued for {$siteIds->count()} site(s)")
                        ->success()
                        ->send();
                }),
            Action::make('run')
                ->label('Run GEO check')
                ->icon('heroicon-o-sparkles')
                ->requiresConfirmation()
                ->modalDescription(fn (): string => 'Queue an AI-search visibility check for '.$this->scopeLabel($this->selectedTenantId()).' (its active prompts). Results appear as each run finishes.')
                ->action(function (): void {
                    $tenantId = $this->selectedTenantId();
                    $siteIds = GeoPrompt::query()->where('active', true)
                        ->when($tenantId !== null, fn ($q) => $q->where('site_id', $tenantId))
                        ->distinct()->pluck('site_id');
                    foreach ($siteIds as $siteId) {
                        SyncSiteGeo::dispatch((string) $siteId);
                    }
                    Notification::make()
                        ->title($siteIds->isEmpty() ? 'No active prompts to check' : "GEO check queued for {$siteIds->count()} site(s)")
                        ->success()
                        ->send();
                }),
            CreateAction::make(),
        ];
    }
}
