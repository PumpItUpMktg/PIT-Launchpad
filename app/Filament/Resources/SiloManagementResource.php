<?php

namespace App\Filament\Resources;

use App\Enums\SiloType;
use App\Filament\Resources\SiloManagementResource\Pages\CreateSilo;
use App\Filament\Resources\SiloManagementResource\Pages\EditSilo;
use App\Filament\Resources\SiloManagementResource\Pages\ListSilos;
use App\Models\Silo;
use App\Operator\Coverage\SiloManager;
use BackedEnum;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Resources\Pages\PageRegistration;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

/**
 * §4 silo management for the §7b workspace: create / view / edit the topical
 * structure (silos, types, pillars) with §4's ViabilityGuard surfaced — a thin
 * silo (below the keyword-support floor) is flagged so the operator can fatten or
 * fold it before it ever holds a lone page. "New silo" commits manual entry
 * through §4's SiloCommitter (see CreateSilo) — geo-neutral validated, with a
 * pillar stub + seeded rule_set — so a hand-made silo is a full citizen, not a
 * raw row. (§4 auto-propose from services/targets is a separate later slice.)
 */
class SiloManagementResource extends Resource
{
    protected static ?string $model = Silo::class;

    protected static string|BackedEnum|null $navigationIcon = 'heroicon-o-rectangle-group';

    protected static ?string $navigationLabel = 'Silos';

    // Demoted from the nav (menu-reorg relay): the Targeting silo-cards page is the primary
    // surface; this table stays routable as its drill-down.
    protected static bool $shouldRegisterNavigation = false;

    protected static string|\UnitEnum|null $navigationGroup = 'Targeting';

    public static function getEloquentQuery(): Builder
    {
        return parent::getEloquentQuery()->withCount(['keywords', 'contents']);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('name')->searchable()->sortable(),
                TextColumn::make('site.brand_name')->label('Tenant')->sortable(),
                TextColumn::make('type')->badge(),
                TextColumn::make('keywords_count')->label('Keywords')->sortable(),
                TextColumn::make('contents_count')->label('Content')->sortable(),
                TextColumn::make('viability')
                    ->label('Viability')
                    ->badge()
                    ->state(fn (Silo $record): string => app(SiloManager::class)->isViable($record) ? 'viable' : 'thin')
                    ->color(fn (string $state): string => $state === 'viable' ? 'success' : 'warning'),
            ])
            ->filters([
                SelectFilter::make('site_id')->label('Tenant')->relationship('site', 'brand_name'),
                SelectFilter::make('type')->options(self::typeOptions()),
            ]);
    }

    public static function form(Schema $schema): Schema
    {
        return $schema->components([
            TextInput::make('name')->required(),
            Select::make('type')->options(self::typeOptions())->required(),
            TextInput::make('status'),
        ]);
    }

    /**
     * @return array<string, string>
     */
    private static function typeOptions(): array
    {
        $options = [];
        foreach (SiloType::cases() as $type) {
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
            'index' => ListSilos::route('/'),
            'create' => CreateSilo::route('/create'),
            'edit' => EditSilo::route('/{record}/edit'),
        ];
    }
}
