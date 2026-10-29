<?php

namespace App\Filament\Resources;

use App\Enums\ContentKind;
use App\Filament\Resources\LeadFormResource\Pages\CreateLeadForm;
use App\Filament\Resources\LeadFormResource\Pages\EditLeadForm;
use App\Filament\Resources\LeadFormResource\Pages\ListLeadForms;
use App\Models\ConversionConfig;
use App\Models\LeadForm;
use App\Models\Scopes\SiteScope;
use App\Operator\ActiveTenant;
use App\Publishing\Forms\LeadFormResolver;
use App\Publishing\RepushPublished;
use App\Security\Capability;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Actions\DeleteAction;
use Filament\Actions\EditAction;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\PageRegistration;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Support\Facades\Auth;

/**
 * The site's lead-form library: one named iframe embed per form (a Joby / GHL / Jotform form the tenant
 * built per product or per office). A form is assigned where pages are owned — on each Service (its
 * service page) and each Location (the office hub + every town it serves) — and the site default sits
 * on the Connections step. {@see LeadFormResolver} picks the one a page renders;
 * the plugin shows whatever embed rides the page's meta blob, so no plugin change is involved.
 */
class LeadFormResource extends Resource
{
    protected static ?string $model = LeadForm::class;

    protected static string|BackedEnum|null $navigationIcon = 'heroicon-o-inbox-arrow-down';

    protected static ?string $navigationLabel = 'Lead forms';

    protected static string|\UnitEnum|null $navigationGroup = 'Settings';

    protected static ?int $navigationSort = 3;

    /** A System-column control (engine configuration) — operator tier only, like Connections and Feeds. */
    public static function canAccess(): bool
    {
        return Auth::user()?->hasCapability(Capability::ManageEngineControls) ?? false;
    }

    public static function form(Schema $schema): Schema
    {
        return $schema->components([
            TextInput::make('name')->required()->maxLength(120)
                ->helperText('How you\'ll recognise it in the Service and Office dropdowns — e.g. "Montclair office", "Sump pump installation".'),
            Textarea::make('embed')->label('Embed code')->rows(5)->required()
                ->helperText('The form\'s <iframe …> (plus any loader <script>) exactly as the provider gives it. The form itself tells the CRM which page it came from.'),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('name')->searchable()->sortable(),
                TextColumn::make('used_by')->label('Used by')->state(fn (LeadForm $record): string => self::usedBy($record))->wrap(),
                TextColumn::make('updated_at')->dateTime()->sortable(),
            ])
            ->defaultSort('name')
            ->recordActions([EditAction::make(), DeleteAction::make()])
            ->emptyStateHeading('No lead forms yet')
            ->emptyStateDescription('Add one form per office and one per service, then pick them on each Office and Service. The site default lives on the Connections step.');
    }

    /** "2 services · Montclair office · site default" — where the form is assigned. */
    public static function usedBy(LeadForm $form): string
    {
        $parts = [];
        $services = $form->services()->withoutGlobalScope(SiteScope::class)->count();
        if ($services > 0) {
            $parts[] = $services.' service'.($services === 1 ? '' : 's');
        }
        $offices = $form->locations()->withoutGlobalScope(SiteScope::class)->pluck('name')->map('strval')->all();
        if ($offices !== []) {
            $parts[] = count($offices) <= 2 ? implode(', ', $offices) : count($offices).' offices';
        }
        $isDefault = ConversionConfig::withoutGlobalScope(SiteScope::class)
            ->where('site_id', $form->site_id)->where('default_lead_form_id', $form->id)->exists();
        if ($isDefault) {
            $parts[] = 'site default';
        }

        return $parts === [] ? 'not assigned yet' : implode(' · ', $parts);
    }

    /** Re-push the working tenant's published pages so every page picks up its assigned form. */
    public static function repushAction(): Action
    {
        return Action::make('repush')
            ->label('Re-push pages')
            ->icon('heroicon-o-arrow-path')
            ->color('gray')
            ->requiresConfirmation()
            ->modalHeading('Re-push every published page')
            ->modalDescription('Pages render the form assigned to their office or service only when they are re-pushed. This queues every published page of the working site, in waves.')
            ->modalSubmitActionLabel('Re-push')
            ->action(function (): void {
                $site = app(ActiveTenant::class)->site();
                if ($site === null) {
                    Notification::make()->title('Pick a working site first.')->warning()->send();

                    return;
                }
                $result = app(RepushPublished::class)->dispatch($site, [ContentKind::Page]);
                Notification::make()->success()
                    ->title('Re-push queued')
                    ->body($result['count'].' page(s) queued — each renders its assigned lead form as it lands.')
                    ->send();
            });
    }

    /** @return array<string, PageRegistration> */
    public static function getPages(): array
    {
        return [
            'index' => ListLeadForms::route('/'),
            'create' => CreateLeadForm::route('/create'),
            'edit' => EditLeadForm::route('/{record}/edit'),
        ];
    }
}
