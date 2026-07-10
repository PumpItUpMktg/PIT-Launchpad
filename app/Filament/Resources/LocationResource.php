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
use App\Locations\ServedTowns;
use Filament\Forms\Components\Hidden;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\TagsInput;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\Toggle;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\PageRegistration;
use Filament\Resources\Resource;
use Filament\Schemas\Components\Fieldset;
use Filament\Schemas\Components\Utilities\Get;
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

    // Consolidated: the single Locations surface is now the LocationsSetup page (one menu
    // item — where you are + how far you serve, auto-geocoded). This resource stays for
    // the §7 new-site wizard's form components but is no longer its own menu item.
    protected static bool $shouldRegisterNavigation = false;

    protected static ?int $navigationSort = -1;

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
            TextInput::make('primary_category')->label('GBP category')->maxLength(255)
                ->helperText('The Google Business Profile primary category (the GBP import will fill this).'),
            self::servedTownsField(),
            Textarea::make('market_notes')->label('Market notes')->rows(4)
                ->helperText('What only the owner knows about this market — years served, soil/water quirks, response-time claims, neighborhood familiarity. Fed to the page drafter verbatim as trusted context.'),
            self::hoursFieldset(),
            // Populated by the Google import; not operator-edited directly.
            Hidden::make('place_id'),
            Hidden::make('lat'),
            Hidden::make('lng'),
            Hidden::make('address_components'),
        ]);
    }

    /**
     * The served-towns tag input — the GBP service-area list, "Town, ST" per tag. On save the tags
     * normalize to structured rows (new towns geocoded via the Geocoder seam; ungeocodable towns
     * store flagged rather than blocking), and the CANNIBALIZATION GUARD rejects any town already
     * claimed by another location on the same site, naming the conflict. The future GBP API prefills
     * this same field.
     */
    private static function servedTownsField(): TagsInput
    {
        return TagsInput::make('served_towns')
            ->label('Served towns')
            ->placeholder('Montclair, NJ')
            ->helperText('The towns this location serves (its GBP service area) — one page claims each town. "Town, ST" per entry.')
            ->formatStateUsing(fn ($state): array => collect(is_array($state) ? $state : [])
                ->filter(fn ($row) => is_array($row) && trim((string) ($row['name'] ?? '')) !== '')
                ->map(fn (array $row): string => trim((string) $row['name']).(trim((string) ($row['state'] ?? '')) !== '' ? ', '.trim((string) $row['state']) : ''))
                ->values()->all())
            ->rule(fn (Get $get, ?Location $record) => function (string $attribute, mixed $value, \Closure $fail) use ($get, $record): void {
                $siteId = (string) ($get('site_id') ?? $record?->site_id ?? '');
                if ($siteId === '' || ! is_array($value)) {
                    return;
                }
                foreach (app(ServedTowns::class)->conflicts($siteId, $value, $record?->id) as $conflict) {
                    $fail("\"{$conflict['town']}\" is already served by the \"{$conflict['location']}\" location — a town can only belong to one location (one page per town, no cannibalization).");
                }
            })
            ->dehydrateStateUsing(fn ($state, ?Location $record): array => app(ServedTowns::class)->normalize(
                is_array($state) ? $state : [],
                is_array($record?->served_towns) ? $record->served_towns : [],
            ));
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
     * Map Place Details onto the form fields. Hours expand to the flat per-day
     * fields (`hours_mon_state`/`_open`/`_close`) the form edits directly — no
     * repeater re-indexing to lose the day keys. site/email/is_storefront/booking
     * stay the operator's.
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
            'place_id' => $details->placeId,
            ...BusinessHours::toFields($details->hours),
        ], fn ($value) => $value !== null && $value !== '');
    }

    /**
     * Business hours as flat, day-named fields — one row per day, each a
     * state Select (Open / Closed / 24 hours) with open/close inputs shown only
     * when Open. Flat + day-keyed so there is no Filament repeater re-indexing to
     * drop the day keys (the round-trip bug). The page hooks fold these back to
     * the stored day-keyed map via `BusinessHours::fromFields`/`toFields`.
     */
    private static function hoursFieldset(): Fieldset
    {
        $rows = [];
        foreach (BusinessHours::DAYS as $day) {
            $rows[] = Select::make("hours_{$day}_state")
                ->label(ucfirst($day))
                ->options(['open' => 'Open', 'closed' => 'Closed', '24h' => '24 hours'])
                ->default('closed')
                ->selectablePlaceholder(false)
                ->live()
                ->dehydrated();
            $rows[] = TextInput::make("hours_{$day}_open")
                ->label('Open')->placeholder('08:00')->dehydrated()
                ->visible(fn (Get $get): bool => $get("hours_{$day}_state") === 'open');
            $rows[] = TextInput::make("hours_{$day}_close")
                ->label('Close')->placeholder('17:00')->dehydrated()
                ->visible(fn (Get $get): bool => $get("hours_{$day}_state") === 'open');
        }

        return Fieldset::make('Business hours')->columns(3)->schema($rows);
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
