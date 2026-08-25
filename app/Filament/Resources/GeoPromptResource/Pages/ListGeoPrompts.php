<?php

namespace App\Filament\Resources\GeoPromptResource\Pages;

use App\Filament\Resources\GeoPromptResource;
use App\Filament\Widgets\GeoCheckActivityWidget;
use App\Filament\Widgets\GeoCheckStatusWidget;
use App\Jobs\BridgeSiteGeoGaps;
use App\Jobs\SeedSiteGeoPrompts;
use App\Jobs\SyncSiteGeo;
use App\Jobs\TopUpSiteGeoPrompts;
use App\Models\GeoPrompt;
use App\Models\GeoSnapshot;
use App\Models\Service;
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
     * @return array<Action>
     */
    protected function getHeaderActions(): array
    {
        return [
            Action::make('seed')
                ->label('Auto-seed prompts')
                ->icon('heroicon-o-square-3-stack-3d')
                ->requiresConfirmation()
                ->modalDescription('Generate GEO prompts from each site\'s services × priority markets × intents (bounded + idempotent — re-running only adds what\'s new).')
                ->action(function (): void {
                    $siteIds = Service::query()->distinct()->pluck('site_id');
                    foreach ($siteIds as $siteId) {
                        SeedSiteGeoPrompts::dispatch((string) $siteId);
                    }
                    Notification::make()
                        ->title($siteIds->isEmpty() ? 'No sites with services to seed' : "Seeding queued for {$siteIds->count()} site(s)")
                        ->success()
                        ->send();
                }),
            Action::make('topup')
                ->label('Generate top-ups')
                ->icon('heroicon-o-sparkles')
                ->requiresConfirmation()
                ->modalDescription('Use AI to add prompt variants for the absent gaps (prompts no engine cites) — neutral rephrasings + head-to-heads. Bounded; the variants land tagged "assisted" and active.')
                ->action(function (): void {
                    $siteIds = GeoSnapshot::query()->distinct()->pluck('site_id');
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
                ->modalDescription('Turn each absent gap (a prompt no engine cites) into a directed content candidate on the review queue — pinned to the gap\'s service silo, ready to generate & publish. Bounded + idempotent; nothing is drafted or published automatically.')
                ->action(function (): void {
                    $siteIds = GeoSnapshot::query()->distinct()->pluck('site_id');
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
                ->modalDescription('Queue an AI-search visibility check for every site that has active prompts. Results appear as each run finishes.')
                ->action(function (): void {
                    $siteIds = GeoPrompt::query()->where('active', true)->distinct()->pluck('site_id');
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
