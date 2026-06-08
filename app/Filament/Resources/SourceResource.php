<?php

namespace App\Filament\Resources;

use App\Enums\SourceType;
use App\Filament\Resources\SourceResource\Pages\CreateSource;
use App\Filament\Resources\SourceResource\Pages\ListSources;
use App\Models\Source;
use App\Operator\Controls\FeedControl;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Actions\DeleteAction;
use Filament\Forms\Components\KeyValue;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Resources\Pages\PageRegistration;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;

/**
 * §6a feeds control: view / add / remove / enable a tenant's news & source
 * feeds. The backfill window + steady-state freshness cutoff (the 90-day
 * tunables) live on each feed's config.
 */
class SourceResource extends Resource
{
    protected static ?string $model = Source::class;

    protected static string|BackedEnum|null $navigationIcon = 'heroicon-o-rss';

    protected static ?string $navigationLabel = 'Feeds';

    protected static string|\UnitEnum|null $navigationGroup = 'Controls';

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('site.brand_name')->label('Tenant')->sortable(),
                TextColumn::make('type')->badge(),
                TextColumn::make('schedule')->placeholder('—'),
                IconColumn::make('enabled')->boolean(),
            ])
            ->filters([
                SelectFilter::make('site_id')->label('Tenant')->relationship('site', 'brand_name'),
                SelectFilter::make('enabled')->options([1 => 'Enabled', 0 => 'Disabled']),
            ])
            ->recordActions([
                Action::make('toggle')
                    ->label(fn (Source $record): string => $record->enabled ? 'Disable' : 'Enable')
                    ->icon('heroicon-o-power')
                    ->action(function (Source $record): void {
                        $control = app(FeedControl::class);
                        $record->enabled ? $control->disable($record) : $control->enable($record);
                    }),
                DeleteAction::make(),
            ]);
    }

    public static function form(Schema $schema): Schema
    {
        return $schema->components([
            Select::make('site_id')->relationship('site', 'brand_name')->required(),
            Select::make('type')->options(self::typeOptions())->required(),
            TextInput::make('schedule')->helperText('e.g. hourly, daily'),
            KeyValue::make('config')->helperText('Backfill window / freshness cutoff and feed settings.'),
            Toggle::make('enabled')->default(true),
        ]);
    }

    /**
     * @return array<string, string>
     */
    private static function typeOptions(): array
    {
        $options = [];
        foreach (SourceType::cases() as $type) {
            $options[$type->value] = ucwords(str_replace('_', ' ', $type->value));
        }

        return $options;
    }

    /**
     * @return array<string, PageRegistration>
     */
    public static function getPages(): array
    {
        return [
            'index' => ListSources::route('/'),
            'create' => CreateSource::route('/create'),
        ];
    }
}
