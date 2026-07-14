<?php

namespace App\Filament\Resources;

use App\Filament\Resources\ServiceResource\Pages\CreateService;
use App\Filament\Resources\ServiceResource\Pages\EditService;
use App\Filament\Resources\ServiceResource\Pages\ListServices;
use App\Models\Service;
use BackedEnum;
use Filament\Actions\ActionGroup;
use Filament\Actions\DeleteAction;
use Filament\Actions\EditAction;
use Filament\Forms\Components\Repeater;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Resources\Pages\PageRegistration;
use Filament\Resources\Resource;
use Filament\Schemas\Components\Component;
use Filament\Schemas\Components\Fieldset;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Schema;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;

/**
 * Service-record ENRICHMENT (hub+spoke relay): the post-intake fields the spoke-page composer
 * renders — symptoms, scope, process, cost factors + honest price range, the owner-triggered
 * comparison block. Same pattern as served_towns on locations: onboarding captures the catalog;
 * the operator enriches here afterwards. Everything except name/site is optional — an absent
 * field means its page section data-gates (degrade by omission), never a blank shell.
 */
class ServiceResource extends Resource
{
    protected static ?string $model = Service::class;

    protected static string|BackedEnum|null $navigationIcon = 'heroicon-o-wrench-screwdriver';

    protected static ?string $navigationLabel = 'Services';

    protected static string|\UnitEnum|null $navigationGroup = 'Settings';

    protected static ?int $navigationSort = 1;

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('site.brand_name')->label('Tenant')->sortable(),
                TextColumn::make('name')->searchable()->sortable(),
                TextColumn::make('short_description')->label('Card line')->limit(48)->placeholder('—'),
                IconColumn::make('has_range')->label('Price range')->boolean()
                    ->state(fn (Service $record): bool => self::rangePresent($record)),
                IconColumn::make('comparison_on')->label('Comparison')->boolean()
                    ->state(fn (Service $record): bool => (bool) (($record->comparison ?? [])['enabled'] ?? false)),
                IconColumn::make('warranty_applicable')->label('Warranty')->boolean(),
            ])
            ->filters([
                SelectFilter::make('site_id')->label('Tenant')->relationship('site', 'brand_name'),
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
            ...self::enrichmentComponents(),
        ]);
    }

    /**
     * The service-record enrichment form, minus the tenant select — THE single source for this
     * form (the resource above and the new Setup group's Services review surface both render it;
     * the gathering relay explicitly forbids a second copy).
     *
     * @return list<Component|TextInput>
     */
    public static function enrichmentComponents(): array
    {
        return [
            TextInput::make('name')->required()->maxLength(255),
            TextInput::make('short_description')->label('Card line')->maxLength(160)
                ->helperText('One line for the hub services grid card.'),

            Repeater::make('symptoms')->label('Symptoms — "signs you need this"')
                ->simple(TextInput::make('item')->required()->maxLength(160))
                ->addActionLabel('Add symptom')->default([])
                ->helperText('The search-intent hook: what the customer notices. Rendered as the spoke page\'s symptoms section.'),

            Repeater::make('scope_items')->label('What\'s included')
                ->simple(TextInput::make('item')->required()->maxLength(160))
                ->addActionLabel('Add scope item')->default([])
                ->helperText('Rendered as the checked "what\'s included" list.'),

            Repeater::make('process_steps')->label('Process steps')
                ->simple(TextInput::make('item')->required()->maxLength(160))
                ->addActionLabel('Add step')->default([])
                ->helperText('Ordered. Empty ⇒ the page falls back to your business-wide process.'),

            Repeater::make('cost_factors')->label('Cost factors')
                ->simple(TextInput::make('item')->required()->maxLength(160))
                ->addActionLabel('Add factor')->default([])
                ->helperText('What drives the price — rendered in the cost section.'),

            Fieldset::make('Price range (optional — honest numbers only)')->columns(3)->schema([
                TextInput::make('price_range.low')->label('Low')->numeric()->minValue(0),
                TextInput::make('price_range.high')->label('High')->numeric()->minValue(0),
                TextInput::make('price_range.unit')->label('Unit')->placeholder('per install')->maxLength(40),
            ]),

            Toggle::make('comparison.enabled')->label('Show a comparison section on this page')->live()
                ->helperText('Owner-triggered, off by default — e.g. pedestal vs. submersible.'),
            Fieldset::make('Comparison')->columns(1)
                ->visible(fn (Get $get): bool => (bool) $get('comparison.enabled'))
                ->schema([
                    TextInput::make('comparison.title')->label('Section title')->maxLength(120)
                        ->placeholder('Pedestal vs. submersible: which is right for you?'),
                    TextInput::make('comparison.option_a.name')->label('Option A name')->maxLength(80),
                    Repeater::make('comparison.option_a.points')->label('Option A points')
                        ->simple(TextInput::make('item')->required()->maxLength(200))
                        ->addActionLabel('Add point')->default([]),
                    TextInput::make('comparison.option_b.name')->label('Option B name')->maxLength(80),
                    Repeater::make('comparison.option_b.points')->label('Option B points')
                        ->simple(TextInput::make('item')->required()->maxLength(200))
                        ->addActionLabel('Add point')->default([]),
                    Textarea::make('comparison.verdict')->label('Verdict (optional)')->rows(2)
                        ->helperText('Your honest recommendation line. Points and verdict render as written — nothing is invented.'),
                ]),

            Toggle::make('warranty_applicable')->label('Warranty applies to this service')
                ->helperText('Pulls your warranty trust copy onto the page.'),

            Textarea::make('description')->label('Internal description')->rows(3)
                ->helperText('Grounding context for the drafter (not rendered verbatim).'),
        ];
    }

    /** @return array<string, PageRegistration> */
    public static function getPages(): array
    {
        return [
            'index' => ListServices::route('/'),
            'create' => CreateService::route('/create'),
            'edit' => EditService::route('/{record}/edit'),
        ];
    }

    private static function rangePresent(Service $record): bool
    {
        $range = $record->price_range ?? [];

        return is_numeric($range['low'] ?? null) && is_numeric($range['high'] ?? null);
    }
}
