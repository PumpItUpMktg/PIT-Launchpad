<?php

namespace App\Filament\Resources;

use App\Filament\Resources\LocationResource\Pages\CreateLocation;
use App\Filament\Resources\LocationResource\Pages\EditLocation;
use App\Filament\Resources\LocationResource\Pages\ListLocations;
use App\Integrations\Places\PlaceCandidate;
use App\Integrations\Places\PlaceDetails;
use App\Integrations\Places\PlacesProvider;
use App\Models\Location;
use App\Support\BusinessHours;
use App\Support\Phone;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Actions\ActionGroup;
use Filament\Actions\DeleteAction;
use Filament\Actions\EditAction;
use Filament\Forms\Components\Hidden;
use Filament\Forms\Components\Repeater;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Notifications\Notification;
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
            // Populated by the Google import; not operator-edited directly.
            Hidden::make('place_id'),
            Hidden::make('lat'),
            Hidden::make('lng'),
            Hidden::make('address_components'),
        ]);
    }

    /**
     * "Import from Google" — paste a Maps/GBP URL or type the business name, pick
     * the match, and the form AUTOFILLS for the operator to confirm (never a
     * silent save). Talks to the PlacesProvider seam only.
     */
    public static function importAction(): Action
    {
        return Action::make('importFromGoogle')
            ->label('Import from Google')
            ->icon('heroicon-o-magnifying-glass')
            ->modalSubmitActionLabel('Autofill from this place')
            ->schema([
                TextInput::make('query')
                    ->label('Google Maps URL or business name')
                    ->live(debounce: 600)
                    ->helperText('Paste a Maps/GBP link or type the name, then pick the match below.'),
                Select::make('place_id')
                    ->label('Match')
                    ->searchable()
                    ->options(function (callable $get): array {
                        $query = (string) $get('query');
                        if (trim($query) === '') {
                            return [];
                        }

                        return collect(app(PlacesProvider::class)->search($query))
                            ->mapWithKeys(fn (PlaceCandidate $c): array => [$c->placeId => $c->name.' — '.$c->address])
                            ->all();
                    }),
            ])
            ->action(function (array $data, $livewire): void {
                $placeId = (string) ($data['place_id'] ?? '');
                if ($placeId === '') {
                    return;
                }

                $details = app(PlacesProvider::class)->details($placeId);
                if ($details === null) {
                    Notification::make()->warning()->title('Could not load that place')->send();

                    return;
                }

                $livewire->form->fill(self::fromPlace($details));

                Notification::make()->success()->title('Imported — pick the tenant, review, and save')->send();
            });
    }

    /**
     * Map Place Details onto the form fields (hours as the stored map — the
     * repeater re-hydrates it to rows). site/email/is_storefront/booking stay the
     * operator's.
     *
     * @return array<string, mixed>
     */
    public static function fromPlace(PlaceDetails $details): array
    {
        return array_filter([
            'name' => $details->name,
            'address' => $details->address,
            'address_components' => $details->addressComponents,
            'phone' => $details->phone,
            'lat' => $details->lat,
            'lng' => $details->lng,
            'gbp_url' => $details->gbpUrl,
            'hours' => $details->hours,
            'place_id' => $details->placeId,
        ], fn ($value) => $value !== null && $value !== '');
    }

    private static function hoursRepeater(): Repeater
    {
        return Repeater::make('hours')
            ->label('Business hours')
            ->afterStateHydrated(fn (Repeater $component, mixed $state) => $component->state(BusinessHours::fromStored(is_array($state) ? $state : null)))
            ->dehydrateStateUsing(fn (mixed $state): array => BusinessHours::toStored(is_array($state) ? $state : null))
            ->default(BusinessHours::fromStored(null))
            ->addable(false)->deletable(false)->reorderable(false)
            ->columns(5)
            ->schema([
                TextInput::make('day')->disabled()->dehydrated()->formatStateUsing(fn (?string $state): string => ucfirst((string) $state)),
                Toggle::make('closed')->live()->inline(false),
                Toggle::make('all_day')->label('24h')->live()->inline(false)->visible(fn (callable $get): bool => ! $get('closed')),
                TextInput::make('open')->placeholder('08:00')->visible(fn (callable $get): bool => ! $get('closed') && ! $get('all_day')),
                TextInput::make('close')->placeholder('17:00')->visible(fn (callable $get): bool => ! $get('closed') && ! $get('all_day')),
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
