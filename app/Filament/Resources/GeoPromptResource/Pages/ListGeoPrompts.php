<?php

namespace App\Filament\Resources\GeoPromptResource\Pages;

use App\Filament\Resources\GeoPromptResource;
use App\Jobs\SyncSiteGeo;
use App\Models\GeoPrompt;
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
