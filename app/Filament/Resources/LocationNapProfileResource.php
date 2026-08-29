<?php

namespace App\Filament\Resources;

use App\Filament\Resources\LocationNapProfileResource\Pages\CreateLocationNapProfile;
use App\Filament\Resources\LocationNapProfileResource\Pages\EditLocationNapProfile;
use App\Filament\Resources\LocationNapProfileResource\Pages\ListLocationNapProfiles;
use App\Models\LocationNapProfile;
use BackedEnum;
use Filament\Actions\EditAction;
use Filament\Forms\Components\KeyValue;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TagsInput;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Resources\Pages\PageRegistration;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;

/**
 * The per-location canonical NAP profile (§ Citations) — the one authoritative submission payload every work
 * order renders from, so submissions are byte-identical by construction. Tenant-scoped (BelongsToSite); one
 * profile per location.
 */
class LocationNapProfileResource extends Resource
{
    protected static ?string $model = LocationNapProfile::class;

    protected static string|BackedEnum|null $navigationIcon = 'heroicon-o-identification';

    protected static ?string $navigationLabel = 'NAP profiles';

    protected static string|\UnitEnum|null $navigationGroup = 'Citations';

    protected static ?int $navigationSort = 2;

    public static function form(Schema $schema): Schema
    {
        return $schema->components([
            Select::make('location_id')->relationship('location', 'name')->required()->searchable()
                ->helperText('One profile per location. This is the canonical NAP for that GBP.'),
            TextInput::make('business_name')->required(),
            TextInput::make('address_1')->label('Address line 1')->required(),
            TextInput::make('address_2')->label('Address line 2'),
            TextInput::make('city')->required(),
            TextInput::make('state')->required()->maxLength(20),
            TextInput::make('postal')->required()->maxLength(20),
            TextInput::make('phone_primary')->required()->helperText('The location\'s own local number — canonical. Never a shared/toll-free line.'),
            TextInput::make('phone_secondary')->helperText('Shared/corporate/toll-free — acceptable but never primary.'),
            TextInput::make('website_url')->url(),
            TextInput::make('verification_email')->email()->helperText('Prefer a per-location alias on a domain we control (listings+{id}@…).'),
            KeyValue::make('hours')->keyLabel('Day')->valueLabel('Hours'),
            TagsInput::make('categories'),
            Textarea::make('description_short')->rows(2),
            Textarea::make('description_long')->rows(4),
            Textarea::make('service_area_description')->rows(2),
            TextInput::make('logo_url')->url(),
            TagsInput::make('photo_urls')->helperText('Image URLs (served from R2/CDN).'),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('location.name')->label('Location')->searchable()->sortable(),
                TextColumn::make('business_name')->searchable(),
                TextColumn::make('city')->sortable(),
                TextColumn::make('state'),
                TextColumn::make('phone_primary')->label('Primary phone'),
                TextColumn::make('verification_email')->label('Verify email')->placeholder('—')->color('gray'),
            ])
            ->defaultSort('business_name')
            ->recordActions([EditAction::make()]);
    }

    /**
     * @return array<string, PageRegistration>
     */
    public static function getPages(): array
    {
        return [
            'index' => ListLocationNapProfiles::route('/'),
            'create' => CreateLocationNapProfile::route('/create'),
            'edit' => EditLocationNapProfile::route('/{record}/edit'),
        ];
    }
}
