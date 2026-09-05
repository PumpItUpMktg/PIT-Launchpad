<?php

namespace App\Filament\Resources\KeywordResource\Pages;

use App\Filament\Resources\KeywordResource;
use App\Models\Scopes\SiteScope;
use App\Models\Site;
use App\Operator\ActiveTenant;
use App\Operator\Coverage\GridKeywordSelector;
use Filament\Actions\Action;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\ListRecords;

class ListKeywords extends ListRecords
{
    protected static string $resource = KeywordResource::class;

    /**
     * The working tenant — the {@see ActiveTenant} lock (tenant-lock remediation). The grid-select action
     * scopes to it, so it flags the locked tenant's services and never fans out across the portfolio. The
     * old all-tenant "Tenant" filter is gone; changing tenant is Exit site → Lobby → enter.
     */
    private function selectedTenantId(): ?string
    {
        return app(ActiveTenant::class)->id();
    }

    private function scopeLabel(?string $tenantId): string
    {
        if ($tenantId === null) {
            return 'the selected tenant';
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
            Action::make('addTopLevelToGrid')
                ->label('Add top-level services to grid')
                ->icon('heroicon-o-map-pin')
                ->color('success')
                ->requiresConfirmation()
                ->modalDescription(fn (): string => 'Flags the target keyword of every top-level service/hub page for '.$this->scopeLabel($this->selectedTenantId()).' as a geo-grid keyword — one representative term per top-level service. Each flagged keyword adds a full town scan per coverage run. Pages without a target keyword are skipped.')
                ->action(function (): void {
                    $tenantId = $this->selectedTenantId();
                    if ($tenantId === null) {
                        Notification::make()->warning()->title('Pick a tenant first')
                            ->body('Use the Tenant filter to choose one site — this flags per tenant, not across the whole portfolio.')->send();

                        return;
                    }

                    $site = Site::query()->withoutGlobalScope(SiteScope::class)->find($tenantId);
                    if ($site === null) {
                        return;
                    }

                    $result = app(GridKeywordSelector::class)->addTopLevelServices($site);

                    Notification::make()->success()
                        ->title($result['flagged'] > 0 ? "Added {$result['flagged']} keyword(s) to the grid" : 'No new keywords to add')
                        ->body($result['skipped'] > 0
                            ? "{$result['skipped']} top-level page(s) had no target keyword and were skipped."
                            : 'Top-level service keywords are on the grid.')
                        ->send();
                }),
        ];
    }
}
