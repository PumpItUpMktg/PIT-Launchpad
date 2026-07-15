<?php

namespace App\Filament\Resources;

use App\ContentEngine\Feeds\FeedHealth;
use App\Enums\FeedOrigin;
use App\Enums\FeedStatus;
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

    protected static string|\UnitEnum|null $navigationGroup = 'Settings';

    /** Menu-map family tag: setup-world editor (deep-linked from the new Setup steps). */
    public static function menuTag(): string
    {
        return 'setup';
    }

    protected static ?int $navigationSort = 5;

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('site.brand_name')->label('Tenant')->sortable(),
                TextColumn::make('origin')->badge(),
                TextColumn::make('url')->label('Feed URL')->limit(44)->color('gray')->toggleable(),
                TextColumn::make('health')
                    ->badge()
                    ->state(fn (Source $record): string => ucfirst(app(FeedHealth::class)->status($record)->value))
                    ->color(fn (Source $record): string => match (app(FeedHealth::class)->status($record)) {
                        FeedStatus::Active => 'success',
                        FeedStatus::Paused => 'gray',
                        FeedStatus::Unhealthy => 'danger',
                    }),
                TextColumn::make('last_item_at')->label('Last item')->since()->placeholder('never'),
                TextColumn::make('last_error')->label('Last error')->limit(44)->color('danger')->placeholder('—')->toggleable(),
                IconColumn::make('enabled')->boolean(),
            ])
            ->filters([
                SelectFilter::make('site_id')->label('Tenant')->relationship('site', 'brand_name'),
                SelectFilter::make('origin')->options([FeedOrigin::Generated->value => 'Generated', FeedOrigin::Client->value => 'Client']),
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
