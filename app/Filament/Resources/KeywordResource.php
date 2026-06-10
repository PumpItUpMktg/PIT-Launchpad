<?php

namespace App\Filament\Resources;

use App\Enums\KeywordSource;
use App\Filament\Resources\KeywordResource\Pages\ListKeywords;
use App\Models\Keyword;
use App\Operator\Coverage\KeywordStandings;
use App\Operator\Coverage\PositionTracking;
use App\Operator\Coverage\TargetQueue;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\PageRegistration;
use Filament\Resources\Resource;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

/**
 * The §7b target-queue + coverage-gaps workspace. Lists §5 keyword targets,
 * opportunity-sorted, with the operator priority override (promote/demote), the
 * coverage gap/covered split, and a per-keyword position summary (organic rank,
 * cannibalization, refresh-ROI) from the PositionTracking service.
 */
class KeywordResource extends Resource
{
    protected static ?string $model = Keyword::class;

    protected static string|BackedEnum|null $navigationIcon = 'heroicon-o-magnifying-glass-circle';

    protected static ?string $navigationLabel = 'Targets & gaps';

    protected static string|\UnitEnum|null $navigationGroup = 'Coverage';

    public static function getEloquentQuery(): Builder
    {
        return parent::getEloquentQuery()
            ->orderByDesc('priority')
            ->orderByDesc('opportunity_score');
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('query')->searchable()->wrap(),
                TextColumn::make('site.brand_name')->label('Tenant')->sortable(),
                TextColumn::make('silo.name')->label('Silo')->placeholder('—'),
                TextColumn::make('intent')->badge()->placeholder('—'),
                TextColumn::make('volume')->numeric()->sortable()->placeholder('—'),
                TextColumn::make('difficulty')->numeric()->sortable()->placeholder('—'),
                TextColumn::make('opportunity_score')->label('Opportunity')->numeric(decimalPlaces: 2)->sortable(),
                TextColumn::make('priority')->badge()->sortable(),
                TextColumn::make('coverage')
                    ->label('Coverage')
                    ->badge()
                    ->state(fn (Keyword $record): string => $record->target_content_id === null ? 'gap' : 'covered')
                    ->color(fn (string $state): string => $state === 'gap' ? 'warning' : 'success'),
                TextColumn::make('position')
                    ->label('Position')
                    ->state(fn (Keyword $record): string => self::positionSummary($record)),
            ])
            ->filters([
                SelectFilter::make('site_id')->label('Tenant')->relationship('site', 'brand_name'),
                SelectFilter::make('silo_id')->label('Silo')->relationship('silo', 'name'),
                SelectFilter::make('source')->options(self::sourceOptions()),
                SelectFilter::make('coverage')
                    ->options(['gap' => 'Uncovered (gap)', 'covered' => 'Covered'])
                    ->query(function (Builder $query, array $data): Builder {
                        return match ($data['value'] ?? null) {
                            'gap' => $query->whereNull('target_content_id'),
                            'covered' => $query->whereNotNull('target_content_id'),
                            default => $query,
                        };
                    }),
            ])
            ->recordActions([
                self::editAction(),
                Action::make('promote')->icon('heroicon-o-arrow-up')->color('success')
                    ->action(fn (Keyword $record) => app(TargetQueue::class)->promote($record)),
                Action::make('demote')->icon('heroicon-o-arrow-down')->color('gray')
                    ->action(fn (Keyword $record) => app(TargetQueue::class)->demote($record)),
                Action::make('standings')->icon('heroicon-o-chart-bar')
                    ->action(fn (Keyword $record) => self::notifyStandings($record)),
            ]);
    }

    /**
     * Inline edit — correct a discovered keyword's query, intent, or lifecycle
     * status without a tinker round-trip. Priority stays on promote/demote.
     */
    private static function editAction(): Action
    {
        return Action::make('edit')
            ->icon('heroicon-o-pencil-square')
            ->fillForm(fn (Keyword $record): array => [
                'query' => $record->query,
                'intent' => $record->intent,
                'status' => $record->status,
            ])
            ->schema([
                TextInput::make('query')->required(),
                TextInput::make('intent')->helperText('informational / commercial / transactional'),
                Select::make('status')
                    ->options(['candidate' => 'Candidate', 'active' => 'Active', 'parked' => 'Parked'])
                    ->required(),
            ])
            ->action(function (Keyword $record, array $data): void {
                $record->update([
                    'query' => $data['query'],
                    'intent' => ($data['intent'] ?? '') !== '' ? $data['intent'] : null,
                    'status' => $data['status'],
                ]);
                Notification::make()->success()->title('Keyword updated')->send();
            });
    }

    private static function positionSummary(Keyword $keyword): string
    {
        $standings = app(PositionTracking::class)->forKeyword($keyword);

        $parts = [];
        $parts[] = $standings->organicRank !== null ? "Organic #{$standings->organicRank}" : 'Organic —';
        if ($standings->cannibalizing) {
            $parts[] = '⚠ cannibalizing';
        }
        if ($standings->refreshCount > 0) {
            $parts[] = "↻ {$standings->refreshCount}";
        }

        return implode(' · ', $parts);
    }

    private static function notifyStandings(Keyword $keyword): void
    {
        $standings = app(PositionTracking::class)->forKeyword($keyword);
        $local = array_map(
            fn (array $m): string => "{$m['market_name']}: ".($m['rank'] !== null ? "#{$m['rank']}" : '—'),
            $standings->localByMarket,
        );

        Notification::make()
            ->title('Position standings')
            ->body(self::standingsBody($standings, $local))
            ->info()
            ->persistent()
            ->send();
    }

    /**
     * @param  list<string>  $local
     */
    private static function standingsBody(KeywordStandings $standings, array $local): string
    {
        return implode("\n", array_filter([
            'Organic: '.($standings->organicRank !== null ? "#{$standings->organicRank}" : 'unranked'),
            $local !== [] ? 'Local: '.implode(', ', $local) : null,
            $standings->cannibalizing ? 'Cannibalization flagged' : null,
            "Refreshes: {$standings->refreshCount}",
        ]));
    }

    /**
     * @return array<string, string>
     */
    private static function sourceOptions(): array
    {
        $options = [];
        foreach (KeywordSource::cases() as $source) {
            $options[$source->value] = ucwords(str_replace('_', ' ', $source->value));
        }

        return $options;
    }

    /**
     * @return array<string, PageRegistration>
     */
    public static function getPages(): array
    {
        return [
            'index' => ListKeywords::route('/'),
        ];
    }
}
