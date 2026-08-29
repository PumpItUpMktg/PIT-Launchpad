<?php

namespace App\Filament\Resources;

use App\Enums\AcquisitionType;
use App\Enums\CostPeriod;
use App\Enums\DirectoryScope;
use App\Enums\MultiLocationPolicy;
use App\Enums\SubmissionMethod;
use App\Filament\Resources\DirectoryResource\Pages\CreateDirectory;
use App\Filament\Resources\DirectoryResource\Pages\EditDirectory;
use App\Filament\Resources\DirectoryResource\Pages\ListDirectories;
use App\Models\Directory;
use BackedEnum;
use Filament\Actions\EditAction;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TagsInput;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Resources\Pages\PageRegistration;
use Filament\Resources\Resource;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Schema;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Filters\TernaryFilter;
use Filament\Tables\Table;

/**
 * The GLOBAL citation directory catalog (§ Citations) — one catalog reused by every tenant, the durable
 * asset the module builds on. Operator-only (the whole panel is), NOT tenant-scoped. SEO value (computed)
 * and business value (operator-set) show as two separate 0–100 columns on purpose, plus cost-per-point so
 * "is this worth a couple dollars" is answerable at a glance.
 */
class DirectoryResource extends Resource
{
    protected static ?string $model = Directory::class;

    protected static string|BackedEnum|null $navigationIcon = 'heroicon-o-building-library';

    protected static ?string $navigationLabel = 'Directory catalog';

    protected static string|\UnitEnum|null $navigationGroup = 'Citations';

    protected static ?int $navigationSort = 1;

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
            TextInput::make('name')->required()->maxLength(255),
            TextInput::make('domain')->required()->maxLength(255)->helperText('Bare domain, e.g. yelp.com — matched against scraped result domains.'),
            Select::make('scope')->options(DirectoryScope::options())->default(DirectoryScope::National->value)->required()
                ->live()->helperText('Geo-scoped directories are owned by exactly one location (the one that owns that geography).'),
            TextInput::make('geo_value')->maxLength(255)->placeholder('NJ · Bergen County · Clifton')
                ->visible(fn (Get $get): bool => $get('scope') !== DirectoryScope::National->value),
            TagsInput::make('trade_categories')->helperText('Trades this directory serves; a directory applies when it intersects the tenant\'s trade.'),
            Select::make('authority_tier')->options([1 => '1', 2 => '2', 3 => '3', 4 => '4', 5 => '5'])->default(3)->required()
                ->helperText('1–5, drives scoring weight.'),

            Select::make('acquisition_type')->options(AcquisitionType::options())->default(AcquisitionType::Free->value)->required()->live(),
            Select::make('submission_method')->options(SubmissionMethod::options()),
            TextInput::make('submission_url')->url()->maxLength(1024),
            TextInput::make('cost_amount')->numeric()->prefix('$')->minValue(0)
                ->visible(fn (Get $get): bool => $get('acquisition_type') !== AcquisitionType::Free->value),
            Select::make('cost_period')->options(CostPeriod::options())
                ->visible(fn (Get $get): bool => $get('acquisition_type') !== AcquisitionType::Free->value),
            TextInput::make('avg_turnaround_days')->numeric()->minValue(0)->suffix('days'),
            TextInput::make('effort_minutes')->numeric()->minValue(0)->suffix('min')->helperText('Estimated submission time.'),

            Select::make('multi_location_policy')->options(MultiLocationPolicy::options())->default(MultiLocationPolicy::OnePerAddress->value)->required()
                ->helperText('one_per_business → a sibling\'s listing covers this location (no task). Default one_per_address fails safe.'),
            Toggle::make('requires_client_action')->helperText('Needs the client (membership/license), not VA work.'),
            Textarea::make('ongoing_obligation')->rows(2)->placeholder('e.g. weekly meeting attendance'),

            TextInput::make('domain_rank')->numeric()->minValue(0)->maxValue(100)->helperText('0–100, DataForSEO domain analytics.'),
            TextInput::make('seo_value')->numeric()->minValue(0)->maxValue(100)->helperText('0–100 — COMPUTED (PR 5 fills this). Keep separate from business value.'),
            TextInput::make('business_value')->numeric()->minValue(0)->maxValue(100)->helperText('0–100 — OPERATOR-SET. A chamber can be SEO 15 / Business 80.'),
            Toggle::make('is_nofollow'),

            Textarea::make('notes')->rows(3)->helperText('VA-facing quirks & gotchas. Every rejection should become a note here.'),
            Toggle::make('is_active')->default(true),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('name')->searchable()->sortable()->wrap(),
                TextColumn::make('domain')->searchable()->color('gray'),
                TextColumn::make('scope')->badge()->sortable(),
                TextColumn::make('geo_value')->placeholder('—'),
                TextColumn::make('authority_tier')->label('Tier')->badge()->sortable(),
                TextColumn::make('acquisition_type')->label('Type')->badge()->sortable(),
                TextColumn::make('cost_amount')->label('Cost')->money('usd')->placeholder('free')->sortable(),
                TextColumn::make('seo_value')->label('SEO')->numeric()->sortable()->placeholder('—'),
                TextColumn::make('business_value')->label('Business')->numeric()->sortable()->placeholder('—'),
                TextColumn::make('cost_per_point')->label('Cost/pt')
                    ->state(fn (Directory $record): ?float => $record->costPerValuePoint())
                    ->placeholder('—')->tooltip('Cost ÷ SEO value — the "is it worth a couple dollars" number'),
                IconColumn::make('is_active')->boolean(),
            ])
            ->defaultSort('name')
            ->filters([
                SelectFilter::make('scope')->options(DirectoryScope::options()),
                SelectFilter::make('acquisition_type')->options(AcquisitionType::options()),
                TernaryFilter::make('is_active')->default(true),
                TernaryFilter::make('requires_client_action')->label('Client action'),
            ])
            ->recordActions([EditAction::make()])
            ->toolbarActions([]);
    }

    /**
     * @return array<string, PageRegistration>
     */
    public static function getPages(): array
    {
        return [
            'index' => ListDirectories::route('/'),
            'create' => CreateDirectory::route('/create'),
            'edit' => EditDirectory::route('/{record}/edit'),
        ];
    }
}
