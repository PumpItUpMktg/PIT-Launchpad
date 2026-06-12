<?php

namespace App\Filament\Resources;

use App\Filament\Resources\LocationResource\Pages\CreateLocation;
use App\Filament\Resources\LocationResource\Pages\EditLocation;
use App\Filament\Resources\LocationResource\Pages\ListLocations;
use App\Models\Location;
use App\Support\BusinessHours;
use App\Support\Phone;
use BackedEnum;
use Filament\Actions\ActionGroup;
use Filament\Actions\DeleteAction;
use Filament\Actions\EditAction;
use Filament\Forms\Components\Repeater;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Resources\Pages\PageRegistration;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Filters\TernaryFilter;
use Filament\Tables\Table;

/**
 * §7 onboarding — operator management of a tenant's physical locations (the
 * Slice-1 surface; the new-site wizard reuses these form components). Site-scoped
 * list with a Google-import accelerator; manual entry is a fully equal path.
 */
class LocationResource extends Resource
{
    protected static ?string $model = Location::class;

    protected static string|BackedEnum|null $navigationIcon = 'heroicon-o-map-pin';

    protected static ?string $navigationLabel = 'Locations';

    protected static string|\UnitEnum|null $navigationGroup = 'Controls';

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('id')->label('ID')->copyable()->fontFamily('mono')->size('xs')->toggleable(isToggledHiddenByDefault: true),
                TextColumn::make('site.brand_name')->label('Tenant')->sortable(),
                TextColumn::make('name')->searchable()->sortable(),
                TextColumn::make('city')->state(fn (Location $record): string => self::city($record))->placeholder('—'),
                TextColumn::make('phone')->state(fn (Location $record): string => Phone::format($record->phone))->placeholder('—'),
                IconColumn::make('is_storefront')->label('Storefront')->boolean(),
                IconColumn::make('has_gbp')->label('GBP')->boolean()->state(fn (Location $record): bool => filled($record->gbp_url)),
            ])
            ->filters([
                SelectFilter::make('site_id')->label('Tenant')->relationship('site', 'brand_name'),
                TernaryFilter::make('is_storefront')->label('Storefront'),
            ])
            ->recordActions([
                ActionGroup::make([
                    EditAction::make(),
                    DeleteAction::make(),
                ]),
            ]);
    }

    public static function form(Schema $schema): Schema
    {
        return $schema->components([
            Select::make('site_id')->relationship('site', 'brand_name')->searchable()->preload()->required(),
            TextInput::make('name')->required()->maxLength(255),
            TextInput::make('address')->label('Address')->maxLength(255)
                ->helperText('The formatted display address (Google import fills this).'),
            TextInput::make('phone')->tel()
                ->helperText('Any format — stored as E.164, displayed formatted.')
                ->dehydrateStateUsing(fn (?string $state): ?string => Phone::toE164((string) $state)),
            TextInput::make('email')->email()->maxLength(255),
            Toggle::make('is_storefront')->label('Storefront (walk-in) location'),
            TextInput::make('booking_url')->label('Booking URL')->url(),
            TextInput::make('gbp_url')->label('Google Business Profile URL')->url(),
            self::hoursRepeater(),
        ]);
    }

    private static function hoursRepeater(): Repeater
    {
        return Repeater::make('hours')
            ->label('Business hours')
            ->afterStateHydrated(fn (Repeater $component, mixed $state) => $component->state(BusinessHours::fromStored(is_array($state) ? $state : null)))
            ->dehydrateStateUsing(fn (mixed $state): array => BusinessHours::toStored(is_array($state) ? $state : null))
            ->default(BusinessHours::fromStored(null))
            ->addable(false)->deletable(false)->reorderable(false)
            ->columns(4)
            ->schema([
                TextInput::make('day')->disabled()->dehydrated()->formatStateUsing(fn (?string $state): string => ucfirst((string) $state)),
                Toggle::make('closed')->live()->inline(false),
                TextInput::make('open')->placeholder('08:00')->visible(fn (callable $get): bool => ! $get('closed')),
                TextInput::make('close')->placeholder('17:00')->visible(fn (callable $get): bool => ! $get('closed')),
            ]);
    }

    /**
     * @return array<string, PageRegistration>
     */
    public static function getPages(): array
    {
        return [
            'index' => ListLocations::route('/'),
            'create' => CreateLocation::route('/create'),
            'edit' => EditLocation::route('/{record}/edit'),
        ];
    }

    /** The locality from the structured address components, for the table. */
    private static function city(Location $location): string
    {
        foreach ($location->address_components ?? [] as $component) {
            if (in_array('locality', $component['types'] ?? [], true)) {
                return (string) ($component['long_name'] ?? '');
            }
        }

        return '';
    }
}
