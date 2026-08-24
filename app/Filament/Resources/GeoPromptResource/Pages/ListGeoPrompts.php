<?php

namespace App\Filament\Resources\GeoPromptResource\Pages;

use App\Filament\Resources\GeoPromptResource;
use App\Jobs\SeedSiteGeoPrompts;
use App\Jobs\SyncSiteGeo;
use App\Models\GeoPrompt;
use App\Models\Service;
use Filament\Actions\Action;
use Filament\Actions\CreateAction;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\ListRecords;

class ListGeoPrompts extends ListRecords
{
    protected static string $resource = GeoPromptResource::class;

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
