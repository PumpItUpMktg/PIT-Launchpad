<?php

namespace App\Filament\Resources;

use App\Enums\ScanCadence;
use App\Filament\Resources\CoverageScanPlanResource\Pages\CreateCoverageScanPlan;
use App\Filament\Resources\CoverageScanPlanResource\Pages\EditCoverageScanPlan;
use App\Filament\Resources\CoverageScanPlanResource\Pages\ListCoverageScanPlans;
use App\Models\CoverageScanPlan;
use App\Models\Location;
use App\Models\Scopes\SiteScope;
use App\Operator\Controls\CoveragePlanControl;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Actions\DeleteAction;
use Filament\Actions\EditAction;
use Filament\Forms\Components\Placeholder;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Toggle;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\PageRegistration;
use Filament\Resources\Resource;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Schema;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;

/**
 * Coverage scan scheduler (§7b Controls): the operator picks which keywords each GBP location scans its
 * served towns for, on what cadence (monthly default), sees the rough per-run cost, and can fire a run on
 * demand. Thin over {@see CoveragePlanControl}; the daily `launchpad:run-due-coverage-plans` command drives
 * the schedule. Operator-only via the admin panel gate.
 */
class CoverageScanPlanResource extends Resource
{
    protected static ?string $model = CoverageScanPlan::class;

    protected static string|BackedEnum|null $navigationIcon = 'heroicon-o-calendar-days';

    protected static ?string $navigationLabel = 'Coverage Scans';

    protected static string|\UnitEnum|null $navigationGroup = 'Targeting';

    protected static ?int $navigationSort = 6;

    public static function menuTag(): string
    {
        return 'unaddressed';
    }

    public static function form(Schema $schema): Schema
    {
        return $schema->components([
            Select::make('site_id')->label('Tenant')->relationship('site', 'brand_name')
                ->searchable()->preload()->required()->live(),
            Select::make('location_id')->label('GBP location')
                ->options(fn (Get $get): array => self::locationOptions($get('site_id')))
                ->searchable()->required()->live(),
            Select::make('keyword_ids')->label('Keywords (grouped by silo)')->multiple()
                ->options(fn (Get $get): array => $get('site_id') ? app(CoveragePlanControl::class)->keywordOptions((string) $get('site_id')) : [])
                ->searchable()->live()
                ->helperText('Only keywords flagged is_grid_keyword are offered.'),
            Select::make('cadence')->options(ScanCadence::options())->default(ScanCadence::Monthly->value)->required(),
            Toggle::make('enabled')->default(true)->helperText('Off pauses the schedule; the plan is kept.'),
            Placeholder::make('estimate')->label('Estimated cost per run')
                ->content(fn (Get $get): string => self::estimateLine($get('location_id'), $get('keyword_ids') ?? [])),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('site.brand_name')->label('Tenant')->sortable(),
                TextColumn::make('location_id')->label('Location')
                    ->formatStateUsing(function ($state): string {
                        $location = self::location((string) $state);

                        return $location !== null ? (string) $location->name : '—';
                    }),
                TextColumn::make('keyword_ids')->label('Keywords')
                    ->formatStateUsing(fn ($state): int => is_array($state) ? count($state) : 0)->badge(),
                TextColumn::make('cadence')->badge()
                    ->formatStateUsing(fn (ScanCadence $state): string => $state->label()),
                TextColumn::make('next_run_at')->label('Next run')->since()->placeholder('—'),
                TextColumn::make('estimate')->label('Est. cost/run')
                    ->state(fn (CoverageScanPlan $record): string => self::estimateLine($record->location_id, $record->keyword_ids ?: [], short: true)),
                IconColumn::make('enabled')->boolean(),
            ])
            ->filters([
                SelectFilter::make('site_id')->label('Tenant')->relationship('site', 'brand_name'),
                SelectFilter::make('enabled')->options([1 => 'Enabled', 0 => 'Disabled']),
            ])
            ->recordActions([
                Action::make('runNow')->label('Run now')->icon('heroicon-o-play')
                    ->requiresConfirmation()
                    ->modalDescription(fn (CoverageScanPlan $record): string => 'Queues '.self::estimateLine($record->location_id, $record->keyword_ids ?: []))
                    ->action(function (CoverageScanPlan $record): void {
                        $n = app(CoveragePlanControl::class)->runNow($record);
                        Notification::make()
                            ->title($n > 0 ? "Queued {$n} coverage scan(s)" : 'No keywords on this plan')
                            ->success()->send();
                    }),
                EditAction::make(),
                DeleteAction::make(),
            ]);
    }

    /** @return array<string, string> */
    private static function locationOptions(mixed $siteId): array
    {
        if (! $siteId) {
            return [];
        }

        return Location::withoutGlobalScope(SiteScope::class)
            ->where('site_id', $siteId)->gbpBacked()->orderBy('name')
            ->pluck('name', 'id')->all();
    }

    private static function location(string $locationId): ?Location
    {
        return $locationId === '' ? null : Location::withoutGlobalScope(SiteScope::class)->find($locationId);
    }

    /** @param  list<string>  $keywordIds */
    private static function estimateLine(mixed $locationId, array $keywordIds, bool $short = false): string
    {
        $location = self::location((string) $locationId);
        if ($location === null) {
            return $short ? '—' : 'Pick a GBP location.';
        }
        $e = app(CoveragePlanControl::class)->estimate($location, count($keywordIds));
        $cost = '$'.number_format($e['cost'], 2);

        return $short
            ? "{$e['requests']} req · {$cost}"
            : "{$e['towns']} towns × {$e['keywords']} keyword(s) = {$e['requests']} requests ≈ {$cost}";
    }

    /** @return array<string, PageRegistration> */
    public static function getPages(): array
    {
        return [
            'index' => ListCoverageScanPlans::route('/'),
            'create' => CreateCoverageScanPlan::route('/create'),
            'edit' => EditCoverageScanPlan::route('/{record}/edit'),
        ];
    }
}
