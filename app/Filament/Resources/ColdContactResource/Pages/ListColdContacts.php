<?php

namespace App\Filament\Resources\ColdContactResource\Pages;

use App\Enums\ColdContactStage;
use App\Filament\Resources\ColdContactResource;
use App\Models\ColdContact;
use Filament\Actions\Action;
use Filament\Resources\Pages\ListRecords;
use Filament\Schemas\Components\Tabs\Tab;
use Illuminate\Database\Eloquent\Builder;

/** One tab per pipeline stage, each with its live count. */
class ListColdContacts extends ListRecords
{
    protected static string $resource = ColdContactResource::class;

    /** @return array<string, Tab> */
    public function getTabs(): array
    {
        $counts = ColdContact::query()->selectRaw('stage, count(*) as n')->groupBy('stage')->pluck('n', 'stage');

        $tabs = ['all' => Tab::make('All')->badge((string) $counts->sum())];
        foreach (ColdContactStage::cases() as $stage) {
            $tabs[$stage->value] = Tab::make($stage->label())
                ->badge((string) ($counts[$stage->value] ?? 0))
                ->modifyQueryUsing(fn (Builder $query): Builder => $query->where('stage', $stage->value));
        }

        return $tabs;
    }

    /** @return array<int, Action> */
    protected function getHeaderActions(): array
    {
        return [
            ColdContactResource::settingsAction(),
            ColdContactResource::runAction(),
            ColdContactResource::importAction(),
        ];
    }
}
