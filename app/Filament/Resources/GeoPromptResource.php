<?php

namespace App\Filament\Resources;

use App\Filament\Resources\GeoPromptResource\Pages\CreateGeoPrompt;
use App\Filament\Resources\GeoPromptResource\Pages\EditGeoPrompt;
use App\Filament\Resources\GeoPromptResource\Pages\ListGeoPrompts;
use App\Models\GeoPrompt;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Actions\DeleteAction;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
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
 * GEO (AI-search visibility) — operator-curated test prompts + their latest observed result per prompt.
 * Operator-only (admin panel). Phase 1 is operator-facing only; the client-portal card comes later once the
 * signal is calibrated. "Run GEO check" queues a per-site audit; the weekly schedule keeps it current.
 */
class GeoPromptResource extends Resource
{
    protected static ?string $model = GeoPrompt::class;

    protected static string|BackedEnum|null $navigationIcon = 'heroicon-o-sparkles';

    protected static ?string $navigationLabel = 'AI Search (GEO)';

    protected static string|\UnitEnum|null $navigationGroup = 'Settings';

    protected static ?int $navigationSort = 6;

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('site.brand_name')->label('Tenant')->sortable(),
                TextColumn::make('prompt')->label('Prompt')->limit(60)->wrap(),
                IconColumn::make('latestSnapshot.cited')->label('Cited')->boolean()->placeholder('—'),
                TextColumn::make('latestSnapshot.position')->label('Rank')->placeholder('—'),
                TextColumn::make('latestSnapshot.sentiment')->label('Sentiment')->badge()->placeholder('—')
                    ->color(fn (?string $state): string => match ($state) {
                        'positive' => 'success',
                        'negative' => 'danger',
                        'neutral' => 'warning',
                        default => 'gray',
                    }),
                TextColumn::make('latestSnapshot.checked_at')->label('Checked')->since()->placeholder('never'),
                IconColumn::make('active')->boolean(),
            ])
            ->filters([
                SelectFilter::make('site_id')->label('Tenant')->relationship('site', 'brand_name'),
                SelectFilter::make('active')->options([1 => 'Active', 0 => 'Inactive']),
            ])
            ->recordActions([
                Action::make('toggle')
                    ->label(fn (GeoPrompt $record): string => $record->active ? 'Deactivate' : 'Activate')
                    ->icon('heroicon-o-power')
                    ->action(fn (GeoPrompt $record) => $record->forceFill(['active' => ! $record->active])->save()),
                DeleteAction::make(),
            ]);
    }

    public static function form(Schema $schema): Schema
    {
        return $schema->components([
            Select::make('site_id')->relationship('site', 'brand_name')->required()->searchable(),
            Textarea::make('prompt')->required()->rows(3)
                ->helperText('The question to test in AI search — e.g. "best sump pump repair in Union, NJ".'),
            TextInput::make('label')->helperText('Optional short label for the board.'),
            Toggle::make('active')->default(true),
        ]);
    }

    /**
     * @return array<string, PageRegistration>
     */
    public static function getPages(): array
    {
        return [
            'index' => ListGeoPrompts::route('/'),
            'create' => CreateGeoPrompt::route('/create'),
            'edit' => EditGeoPrompt::route('/{record}/edit'),
        ];
    }
}
