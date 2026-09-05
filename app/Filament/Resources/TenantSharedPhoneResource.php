<?php

namespace App\Filament\Resources;

use App\Enums\SharedPhonePurpose;
use App\Filament\Resources\TenantSharedPhoneResource\Pages\ListTenantSharedPhones;
use App\Models\TenantSharedPhone;
use BackedEnum;
use Filament\Actions\CreateAction;
use Filament\Actions\DeleteAction;
use Filament\Actions\EditAction;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Resources\Pages\PageRegistration;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;

/**
 * The tenant's shared phone numbers (§ Citations) — corporate / emergency / tracking. Captured at onboarding
 * for every multi-location tenant. `owning_location_id` defaults null (zero attribution signal); set it only
 * when a location genuinely owns the number as its GBP primary. Tenant-scoped (BelongsToSite). A simple
 * single-page CRUD (create/edit inline) — this is a short reference list, not a workflow.
 */
class TenantSharedPhoneResource extends Resource
{
    protected static ?string $model = TenantSharedPhone::class;

    protected static string|BackedEnum|null $navigationIcon = 'heroicon-o-phone';

    protected static ?string $navigationLabel = 'Shared phones';

    protected static string|\UnitEnum|null $navigationGroup = 'Citations';

    protected static ?int $navigationSort = 3;

    /**
     * Menu-map bookkeeping (menu-reorg worksheet): the Citations module is mid-build, so its final-menu
     * placement is a pending decision — it inventories as `unaddressed`, not a legacy retire. Does not
     * affect the live sidebar (that comes from navigationGroup).
     */
    public static function menuTag(): string
    {
        return 'unaddressed';
    }

    public static function form(Schema $schema): Schema
    {
        return $schema->components([
            TextInput::make('phone')->required(),
            Select::make('purpose')->options(SharedPhonePurpose::options())->default(SharedPhonePurpose::Corporate->value)->required(),
            Select::make('owning_location_id')->relationship('owningLocation', 'name')->searchable()->label('Owning location (GBP primary)')
                ->helperText('Leave EMPTY unless a location genuinely owns this number as its GBP primary. Attribution still requires address corroboration.'),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('phone')->searchable(),
                TextColumn::make('purpose')->badge()->sortable(),
                TextColumn::make('owningLocation.name')->label('Owns (GBP primary)')->placeholder('— (shared, no attribution)'),
            ])
            ->defaultSort('phone')
            ->recordActions([EditAction::make(), DeleteAction::make()])
            ->headerActions([CreateAction::make()]);
    }

    /**
     * @return array<string, PageRegistration>
     */
    public static function getPages(): array
    {
        return [
            'index' => ListTenantSharedPhones::route('/'),
        ];
    }
}
