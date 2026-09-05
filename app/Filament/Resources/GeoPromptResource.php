<?php

namespace App\Filament\Resources;

use App\Enums\GeoPromptKind;
use App\Enums\GeoPromptPriority;
use App\Filament\Resources\GeoPromptResource\Pages\CreateGeoPrompt;
use App\Filament\Resources\GeoPromptResource\Pages\EditGeoPrompt;
use App\Filament\Resources\GeoPromptResource\Pages\ListGeoPrompts;
use App\Models\CoverageArea;
use App\Models\GeoCheckEvent;
use App\Models\GeoPrompt;
use App\Models\GeoSnapshot;
use App\Models\Location;
use App\Models\Scopes\SiteScope;
use App\Support\WorkingTenant;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Actions\BulkAction;
use Filament\Actions\DeleteAction;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\PageRegistration;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\SelectColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Enums\FiltersLayout;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;

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

    /**
     * Menu-map family tag: a new Phase-1 operator surface whose final-IA placement (and eventual
     * client-portal exposure) is still to be decided — so it inventories under "pending", not the
     * final sidebar.
     */
    public static function menuTag(): string
    {
        return 'unaddressed';
    }

    /**
     * The tenant the screen opens scoped to: the operator's session working site, else the first tenant
     * that already has GEO prompts. Null (all tenants) only when nothing is selected and none exist yet.
     */
    private static function defaultTenantId(): ?string
    {
        $fallback = GeoPrompt::query()->withoutGlobalScope(SiteScope::class)->value('site_id');

        return WorkingTenant::id() ?? (is_string($fallback) ? $fallback : null);
    }

    public static function table(Table $table): Table
    {
        return $table
            // Refresh live so the "Checked" times + cited badges update as a running GEO check measures.
            ->poll('15s')
            ->columns([
                TextColumn::make('kind')->label('Lane')->badge()
                    ->formatStateUsing(fn (GeoPromptKind $state): string => $state->label())
                    ->color(fn (GeoPromptKind $state): string => $state === GeoPromptKind::Coverage ? 'warning' : 'gray'),
                TextColumn::make('prompt')->label('Prompt')->limit(60)->wrap(),
                // Cited in N of M engines that have a reading (latest per engine).
                TextColumn::make('cited_engines')->label('Cited (engines)')->badge()
                    ->state(function (GeoPrompt $record): string {
                        $s = $record->engineSummary();

                        return $s['measured'] === 0 ? '—' : $s['cited'].'/'.$s['measured'];
                    })
                    ->color(function (GeoPrompt $record): string {
                        $s = $record->engineSummary();

                        return match (true) {
                            $s['measured'] === 0 => 'gray',
                            $s['cited'] === 0 => 'danger',
                            $s['cited'] === $s['measured'] => 'success',
                            default => 'warning',
                        };
                    }),
                // Inline operator priority — the "let the user prioritize" lever; leads the check order.
                SelectColumn::make('priority')->label('Priority')->options(GeoPromptPriority::options())->selectablePlaceholder(false),
                TextColumn::make('latestSnapshot.checked_at')->label('Checked')->since()->placeholder('never'),
                IconColumn::make('active')->boolean(),
            ])
            ->filters([
                // View one brick-and-mortar shop's prompts at a time (its towns, via source_location_ids).
                SelectFilter::make('location')->label('Brick & mortar')
                    ->options(fn (): array => self::locationOptions())
                    ->query(fn (Builder $query, array $data): Builder => self::scopeToLocation($query, $data['value'] ?? null)),
                SelectFilter::make('kind')->label('Lane')->options(GeoPromptKind::options()),
                SelectFilter::make('priority')->options(GeoPromptPriority::options()),
                SelectFilter::make('active')->options([1 => 'Active', 0 => 'Inactive']),
            ], layout: FiltersLayout::AboveContent)
            ->recordActions([
                Action::make('toggle')
                    ->label(fn (GeoPrompt $record): string => $record->active ? 'Deactivate' : 'Activate')
                    ->icon('heroicon-o-power')
                    ->action(fn (GeoPrompt $record) => $record->forceFill(['active' => ! $record->active])->save()),
                DeleteAction::make(),
            ])
            ->bulkActions([
                BulkAction::make('deleteSelected')
                    ->label('Delete selected')
                    ->icon('heroicon-o-trash')
                    ->color('danger')
                    ->requiresConfirmation()
                    ->modalDescription('Permanently delete the selected GEO prompts and their snapshots. Use the Brick & mortar + Lane filters to select an area (e.g. a state you\'re not targeting), then delete.')
                    ->action(function (Collection $records): void {
                        $ids = $records->pluck('id')->all();
                        GeoSnapshot::withoutGlobalScope(SiteScope::class)->whereIn('geo_prompt_id', $ids)->delete();
                        GeoCheckEvent::withoutGlobalScope(SiteScope::class)->whereIn('geo_prompt_id', $ids)->delete();
                        GeoPrompt::withoutGlobalScope(SiteScope::class)->whereIn('id', $ids)->delete();

                        Notification::make()->success()->title('Deleted '.count($ids).' prompt(s)')->send();
                    }),
            ]);
    }

    /**
     * The brick-and-mortar shops (of the default/working tenant) for the location filter.
     *
     * @return array<string, string>
     */
    private static function locationOptions(): array
    {
        $query = Location::withoutGlobalScope(SiteScope::class)->whereNull('merged_into_id');
        $tenant = self::defaultTenantId();
        if ($tenant !== null) {
            $query->where('site_id', $tenant);
        }

        return $query->orderBy('name')->pluck('name', 'id')->all();
    }

    /**
     * Scope the prompt list to the towns owned by one brick-and-mortar shop (source_location_ids). Resolved
     * in PHP so it's DB-agnostic across the JSON column.
     *
     * @param  Builder<GeoPrompt>  $query
     * @return Builder<GeoPrompt>
     */
    private static function scopeToLocation(Builder $query, mixed $locationId): Builder
    {
        if (! is_string($locationId) || $locationId === '') {
            return $query;
        }

        $townIds = CoverageArea::withoutGlobalScope(SiteScope::class)
            ->get(['id', 'source_location_ids'])
            ->filter(fn (CoverageArea $t): bool => in_array($locationId, $t->source_location_ids ?? [], true))
            ->pluck('id')->all();

        return $query->whereIn('coverage_area_id', $townIds);
    }

    public static function form(Schema $schema): Schema
    {
        return $schema->components([
            Textarea::make('prompt')->required()->rows(3)
                ->helperText('The question to test in AI search — e.g. "best sump pump repair in Union, NJ".'),
            TextInput::make('label')->helperText('Optional short label for the board.'),
            Select::make('priority')->options(GeoPromptPriority::options())->default(GeoPromptPriority::Normal->value)
                ->helperText('High-priority prompts are checked and turned into content first.'),
            Toggle::make('active')->default(true),
        ]);
    }

    public static function getEloquentQuery(): Builder
    {
        // Eager-load snapshots so the per-engine "cited N/M" column doesn't N+1 across the board.
        return parent::getEloquentQuery()->with('snapshots');
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
