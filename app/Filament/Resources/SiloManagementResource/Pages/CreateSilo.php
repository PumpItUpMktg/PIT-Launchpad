<?php

namespace App\Filament\Resources\SiloManagementResource\Pages;

use App\Enums\SiloType;
use App\Filament\Resources\SiloManagementResource;
use App\Models\Scopes\SiteScope;
use App\Models\Silo;
use App\Models\Site;
use App\SiloCreator\GeoNeutralViolationException;
use App\SiloCreator\ManualSiloCreator;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TagsInput;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\CreateRecord;
use Filament\Schemas\Schema;
use Illuminate\Database\Eloquent\Model;

/**
 * Manual silo create — distinct from the resource's edit form: it collects only
 * what SiloCommitter needs to build a valid silo (tenant, name, type, seed terms)
 * and commits through §4's ManualSiloCreator so the silo gets its geo-neutral
 * check, pillar Content stub, and seeded rule_set. A geo violation halts the
 * create with the offending terms surfaced — never a half-written row.
 */
class CreateSilo extends CreateRecord
{
    protected static string $resource = SiloManagementResource::class;

    public function form(Schema $schema): Schema
    {
        return $schema->components([
            Select::make('site_id')
                ->label('Tenant')
                ->options(fn (): array => Site::query()->orderBy('brand_name')->pluck('brand_name', 'id')->all())
                ->searchable()
                ->required(),
            TextInput::make('name')
                ->label('Silo name')
                ->required()
                ->helperText('Geo-neutral — no city/market terms (locations live on location pages, not silos).'),
            Select::make('type')
                ->options([
                    SiloType::ServicePillar->value => 'Service pillar',
                    SiloType::Topical->value => 'Topical',
                ])
                ->default(SiloType::ServicePillar->value)
                ->required(),
            TagsInput::make('seed_terms')
                ->label('Seed terms')
                ->required()
                ->helperText('The topical boundary §5 refines with SERP signal — e.g. the core service phrases. Press Enter after each.'),
        ]);
    }

    /**
     * Route the manual entry through §4's commit path. A geo-neutral violation is
     * surfaced and the create is halted (no partial row).
     *
     * @param  array<string, mixed>  $data
     */
    protected function handleRecordCreation(array $data): Model
    {
        $site = Site::query()->findOrFail($data['site_id']);
        $seedTerms = array_values(array_map('strval', (array) ($data['seed_terms'] ?? [])));

        try {
            $silo = app(ManualSiloCreator::class)->create(
                $site,
                SiloType::from((string) $data['type']),
                (string) $data['name'],
                $seedTerms,
            );

            // Site-scoped record — re-load without the global scope so the edit
            // redirect resolves it regardless of the panel's current-site resolution.
            return Silo::withoutGlobalScope(SiteScope::class)->findOrFail($silo->id);
        } catch (GeoNeutralViolationException $e) {
            Notification::make()->danger()
                ->title('Silo name/terms are not geo-neutral')
                ->body('Remove the geo term(s): '.implode(', ', $e->violations).'. Geo lives on location pages, never silos.')
                ->send();

            $this->halt();

            throw $e; // unreachable — halt() throws; satisfies the total return type.
        }
    }
}
