<?php

namespace App\Filament\Resources;

use App\ColdOutreach\ColdPipeline;
use App\ColdOutreach\ContactImporter;
use App\Enums\ColdContactStage;
use App\Enums\ConnectionProvider;
use App\Filament\Resources\ColdContactResource\Pages\ListColdContacts;
use App\Http\Controllers\ColdOutreach\InstantlyWebhookController;
use App\Integrations\ColdOutreach\ColdOutreachException;
use App\Integrations\ColdOutreach\OutreachCredentials;
use App\Jobs\AdvanceColdPipeline;
use App\Models\ColdContact;
use App\Models\ColdOutreachConfig;
use App\Models\Scopes\SiteScope;
use App\Models\Site;
use App\Operator\ActiveTenant;
use App\Security\Capability;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Actions\BulkAction;
use Filament\Actions\DeleteBulkAction;
use Filament\Forms\Components\FileUpload;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\PageRegistration;
use Filament\Resources\Resource;
use Filament\Schemas\Components\Section;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

/**
 * Cold outreach: contacts from a cold-email database, verified with Bird, warmed in Instantly and
 * promoted to Brevo once they engage. The working tenant's pipeline, one tab per stage; logic lives in
 * {@see ColdPipeline} — this surface only imports, configures, and lets the operator decide the cases
 * the pipeline will not decide alone (risky addresses, manual promotion).
 */
class ColdContactResource extends Resource
{
    protected static ?string $model = ColdContact::class;

    protected static string|BackedEnum|null $navigationIcon = 'heroicon-o-envelope-open';

    protected static ?string $navigationLabel = 'Cold outreach';

    protected static ?string $modelLabel = 'cold contact';

    protected static string|\UnitEnum|null $navigationGroup = 'Settings';

    protected static ?int $navigationSort = 4;

    public static function canAccess(): bool
    {
        return Auth::user()?->hasCapability(Capability::ManageEngineControls) ?? false;
    }

    public static function canCreate(): bool
    {
        return false;
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('email')->searchable()->copyable(),
                TextColumn::make('name')->state(fn (ColdContact $r): string => $r->fullName())
                    ->searchable(['first_name', 'last_name']),
                TextColumn::make('company')->searchable()->toggleable(),
                TextColumn::make('stage')->badge()
                    ->formatStateUsing(fn (ColdContactStage $state): string => $state->label())
                    ->color(fn (ColdContactStage $state): string => $state->color()),
                TextColumn::make('verify_result')->label('Bird')
                    ->state(fn (ColdContact $r): ?string => $r->verify_result === null ? null
                        : $r->verify_result.' · '.$r->verify_confidence
                            .($r->verify_flags ? ' · '.implode(', ', $r->verify_flags) : '')
                            .($r->did_you_mean ? ' → '.$r->did_you_mean : '')),
                TextColumn::make('engagement')->label('Instantly')
                    ->state(fn (ColdContact $r): ?string => $r->warming_started_at === null ? null
                        : "{$r->opens} opens · {$r->replies} replies"),
                TextColumn::make('outcome')->label('Why')
                    ->state(fn (ColdContact $r): ?string => $r->last_error ?? $r->warm_reason ?? $r->disqualified_reason ?? $r->verify_reason)
                    ->color(fn (ColdContact $r): ?string => $r->last_error !== null ? 'danger' : null)
                    ->wrap(),
                TextColumn::make('source')->toggleable(isToggledHiddenByDefault: true),
                TextColumn::make('updated_at')->since()->sortable(),
            ])
            ->defaultSort('updated_at', 'desc')
            ->filters([
                SelectFilter::make('source')->options(fn (): array => ColdContact::query()
                    ->whereNotNull('source')->distinct()->orderBy('source')->pluck('source', 'source')->all()),
            ])
            ->recordActions([
                Action::make('approve')->label('Approve')->icon('heroicon-o-check')
                    ->visible(fn (ColdContact $r): bool => $r->stage === ColdContactStage::Risky)
                    ->action(fn (ColdContact $r) => self::approve(new Collection([$r]))),
                Action::make('promote')->label('Send to Brevo')->icon('heroicon-o-paper-airplane')
                    ->visible(fn (ColdContact $r): bool => $r->stage === ColdContactStage::Warm)
                    ->action(fn (ColdContact $r) => self::promote(new Collection([$r]))),
                Action::make('warm')->label('Mark warm')->icon('heroicon-o-fire')->color('gray')
                    ->visible(fn (ColdContact $r): bool => $r->stage === ColdContactStage::Warming)
                    ->requiresConfirmation()
                    ->modalDescription('Use this when the contact engaged outside Instantly (a call, a LinkedIn reply). It will go to Brevo on the next pass.')
                    ->action(fn (ColdContact $r) => $r->forceFill(['stage' => ColdContactStage::Warm, 'warm_at' => now(), 'warm_reason' => 'marked by operator'])->save()),
                Action::make('drop')->label('Disqualify')->icon('heroicon-o-x-mark')->color('danger')
                    ->visible(fn (ColdContact $r): bool => ! $r->stage->isTerminal())
                    ->requiresConfirmation()
                    ->action(fn (ColdContact $r) => $r->forceFill(['stage' => ColdContactStage::Disqualified, 'disqualified_reason' => 'removed by operator'])->save()),
            ])
            ->toolbarActions([
                BulkAction::make('approve')->label('Approve risky')->icon('heroicon-o-check')
                    ->action(fn (Collection $records) => self::approve($records)),
                BulkAction::make('promote')->label('Send warm to Brevo')->icon('heroicon-o-paper-airplane')
                    ->action(fn (Collection $records) => self::promote($records)),
                DeleteBulkAction::make(),
            ])
            ->emptyStateHeading('No cold contacts yet')
            ->emptyStateDescription('Import a CSV from your cold-email database, set the Instantly campaign and Brevo list under Settings, then switch the pipeline on.');
    }

    /** @param  Collection<int, ColdContact>  $records */
    public static function approve(Collection $records): void
    {
        $n = 0;
        foreach ($records as $record) {
            if ($record->stage === ColdContactStage::Risky) {
                $record->forceFill(['stage' => ColdContactStage::Verified, 'verify_reason' => 'approved by operator'])->save();
                $n++;
            }
        }
        Notification::make()->success()->title("{$n} approved")->body('They will be added to Instantly on the next pass.')->send();
    }

    /** @param  Collection<int, ColdContact>  $records */
    public static function promote(Collection $records): void
    {
        $site = self::site();
        if ($site === null) {
            return;
        }
        try {
            $n = app(ColdPipeline::class)->promoteContacts($site, ColdOutreachConfig::forSite($site->id), $records->values()->all());
        } catch (ColdOutreachException $e) {
            Notification::make()->danger()->title('Brevo refused')->body($e->getMessage())->send();

            return;
        }
        Notification::make()->success()->title("{$n} sent to Brevo")->send();
    }

    public static function importAction(): Action
    {
        return Action::make('import')
            ->label('Import CSV')
            ->icon('heroicon-o-arrow-up-tray')
            ->modalDescription('A CSV export from Apollo, ZoomInfo, Clay or any spreadsheet. Needs an Email column; First/Last name, Company, Title, Phone and Website are recognised, and every other column becomes an Instantly personalization variable. Contacts already in the pipeline are skipped.')
            ->schema([
                FileUpload::make('file')->label('CSV file')->required()
                    ->disk('local')->directory('cold-imports')
                    ->acceptedFileTypes(['text/csv', 'text/plain', 'application/csv', 'application/vnd.ms-excel'])
                    ->maxSize(20480),
                TextInput::make('source')->label('Source label')->placeholder('e.g. Apollo — HVAC owners NJ')->maxLength(120),
            ])
            ->action(function (array $data): void {
                $site = self::site();
                if ($site === null) {
                    return;
                }
                $path = (string) $data['file'];
                try {
                    $result = app(ContactImporter::class)->importFile($site, Storage::disk('local')->path($path),
                        ($data['source'] ?? '') !== '' ? (string) $data['source'] : null);
                } finally {
                    Storage::disk('local')->delete($path);
                }
                if ($result->errors !== []) {
                    Notification::make()->danger()->title('Import failed')->body(implode(' ', $result->errors))->send();

                    return;
                }
                Notification::make()->success()->title('Imported')->body($result->summary())->send();
            });
    }

    public static function runAction(): Action
    {
        return Action::make('run')
            ->label('Run pipeline now')
            ->icon('heroicon-o-play')
            ->color('gray')
            ->requiresConfirmation()
            ->modalDescription('Verifies new imports with Bird (billed per address), adds verified contacts to the Instantly campaign, syncs engagement and sends warm contacts to Brevo. Runs in the background.')
            ->action(function (): void {
                $site = self::site();
                if ($site === null) {
                    return;
                }
                AdvanceColdPipeline::dispatch($site->id);
                Notification::make()->success()->title('Pipeline queued')->body('Refresh in a minute to see contacts move.')->send();
            });
    }

    public static function settingsAction(): Action
    {
        return Action::make('settings')
            ->label('Settings')
            ->icon('heroicon-o-cog-6-tooth')
            ->color('gray')
            ->fillForm(function (): array {
                $site = self::site();

                return $site === null ? [] : ColdOutreachConfig::forSite($site->id)->only([
                    'enabled', 'instantly_campaign_id', 'brevo_list_id', 'min_confidence', 'accept_neutral',
                    'warm_on_reply', 'warm_on_interested', 'warm_min_opens', 'auto_promote',
                ]) + ['webhook_url' => self::webhookUrl($site), 'webhook_token' => app(OutreachCredentials::class)->webhookSecret($site)];
            })
            ->schema(fn (): array => [
                Section::make('Pipeline')->schema([
                    Toggle::make('enabled')->label('Run hourly')
                        ->helperText('Off: nothing moves except when you press "Run pipeline now".'),
                    TextInput::make('instantly_campaign_id')->label('Instantly campaign ID')
                        ->helperText('The warm-up campaign verified contacts are added to (Instantly → Campaign → URL).'),
                    TextInput::make('brevo_list_id')->label('Brevo list ID')->numeric()->minValue(1)
                        ->helperText('Warm contacts are added to this list (Brevo → Contacts → Lists).'),
                ]),
                Section::make('Deliverability (Bird)')->schema([
                    TextInput::make('min_confidence')->label('Minimum delivery confidence')->numeric()->minValue(0)->maxValue(100)->required(),
                    Toggle::make('accept_neutral')->label('Accept "neutral" (catch-all) addresses')
                        ->helperText('Neutral addresses can\'t be confirmed either way. Off: they wait in Risky for your review.'),
                ])->columns(2),
                Section::make('When is a contact warm?')->schema([
                    Toggle::make('warm_on_reply')->label('They replied'),
                    Toggle::make('warm_on_interested')->label('Tagged interested / meeting booked in Instantly'),
                    TextInput::make('warm_min_opens')->label('…or opened at least N emails (0 = off)')->numeric()->minValue(0)
                        ->helperText('Opens are unreliable (Apple privacy, image proxies) and are not consent — keep at 0 unless you have a reason.'),
                    Toggle::make('auto_promote')->label('Send warm contacts to Brevo automatically')
                        ->helperText('Off: warm contacts wait for you to press "Send to Brevo".'),
                ])->columns(2),
                Section::make('API keys (this site only)')
                    ->description('Leave blank to keep the current key, or to use the platform key from the server environment.')
                    ->schema([
                        TextInput::make('bird_key')->label('Bird API key')->password()->revealable(false)
                            ->placeholder(self::keyState(ConnectionProvider::Bird)),
                        TextInput::make('instantly_key')->label('Instantly API key')->password()->revealable(false)
                            ->placeholder(self::keyState(ConnectionProvider::Instantly)),
                        TextInput::make('brevo_key')->label('Brevo API key')->password()->revealable(false)
                            ->placeholder(self::keyState(ConnectionProvider::Brevo)),
                    ])->columns(3),
                Section::make('Instantly webhook')
                    ->description('In Instantly → Settings → Integrations → Webhooks, add this URL for "All events" (or this campaign) with a custom header X-Launchpad-Token set to the token below. Without it, engagement still syncs hourly.')
                    ->schema([
                        TextInput::make('webhook_url')->label('URL')->disabled()->dehydrated(false)->copyable(),
                        TextInput::make('webhook_token')->label(InstantlyWebhookController::HEADER)->disabled()->dehydrated(false)->copyable()
                            ->placeholder('Not generated yet — tick below and save'),
                        Toggle::make('regenerate_token')->label('Generate a new token')->dehydrated(),
                    ]),
            ])
            ->action(function (array $data): void {
                $site = self::site();
                if ($site === null) {
                    return;
                }
                ColdOutreachConfig::withoutGlobalScope(SiteScope::class)->updateOrCreate(['site_id' => $site->id], [
                    'enabled' => (bool) ($data['enabled'] ?? false),
                    'instantly_campaign_id' => ($data['instantly_campaign_id'] ?? '') !== '' ? trim((string) $data['instantly_campaign_id']) : null,
                    'brevo_list_id' => ($data['brevo_list_id'] ?? '') !== '' ? (int) $data['brevo_list_id'] : null,
                    'min_confidence' => (int) ($data['min_confidence'] ?? 70),
                    'accept_neutral' => (bool) ($data['accept_neutral'] ?? true),
                    'warm_on_reply' => (bool) ($data['warm_on_reply'] ?? true),
                    'warm_on_interested' => (bool) ($data['warm_on_interested'] ?? true),
                    'warm_min_opens' => (int) ($data['warm_min_opens'] ?? 0),
                    'auto_promote' => (bool) ($data['auto_promote'] ?? true),
                ]);

                $credentials = app(OutreachCredentials::class);
                foreach (['bird_key' => ConnectionProvider::Bird, 'instantly_key' => ConnectionProvider::Instantly, 'brevo_key' => ConnectionProvider::Brevo] as $field => $provider) {
                    $key = trim((string) ($data[$field] ?? ''));
                    if ($key !== '') {
                        $credentials->store($site, $provider, ['api_key' => $key]);
                    }
                }
                if (($data['regenerate_token'] ?? false) || $credentials->webhookSecret($site) === null) {
                    $credentials->store($site, ConnectionProvider::Instantly, ['webhook_secret' => Str::random(48)]);
                }

                Notification::make()->success()->title('Cold outreach settings saved')->send();
            });
    }

    private static function keyState(ConnectionProvider $provider): string
    {
        $site = self::site(notify: false);
        if ($site === null) {
            return '';
        }
        $credentials = app(OutreachCredentials::class);
        if ($credentials->hasSiteConnection($site, $provider) && $credentials->isConfigured($site, $provider)) {
            return 'Site key set';
        }

        return $credentials->isConfigured($site, $provider) ? 'Using platform key' : 'Not set';
    }

    private static function webhookUrl(Site $site): string
    {
        return route('webhooks.instantly', ['site' => $site->id]);
    }

    private static function site(bool $notify = true): ?Site
    {
        $site = app(ActiveTenant::class)->site();
        if ($site === null && $notify) {
            Notification::make()->warning()->title('Pick a working site first.')->send();
        }

        return $site;
    }

    /** @return array<string, PageRegistration> */
    public static function getPages(): array
    {
        return [
            'index' => ListColdContacts::route('/'),
        ];
    }
}
