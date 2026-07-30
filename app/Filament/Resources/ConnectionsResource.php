<?php

namespace App\Filament\Resources;

use App\Filament\Resources\ConnectionsResource\Pages\ListConnections;
use App\Integrations\Google\GoogleConnectionService;
use App\Integrations\Google\GoogleException;
use App\Models\Connection;
use App\Models\GoogleAccount;
use App\Models\Site;
use App\Models\User;
use App\Operator\Controls\WordpressConnector;
use App\Security\ConnectionRotator;
use App\Security\CredentialMasker;
use App\Security\CredentialRevealer;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Forms\Components\KeyValue;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\PageRegistration;
use Filament\Resources\Resource;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Support\Facades\Auth;
use Throwable;

/**
 * §9 connection management for the §7b controls. Credentials are shown masked
 * (`••••1234`); the explicit, audited reveal returns plaintext only to an
 * operator (`CredentialRevealer` writes the audit row); rotate is wired to §9's
 * verify-before-revoke `ConnectionRotator`; compromised / unrotated flags
 * surface the launch-gate checklist. Operator-only (the panel is, and reveal/
 * rotate are policy-gated).
 */
class ConnectionsResource extends Resource
{
    protected static ?string $model = Connection::class;

    protected static string|BackedEnum|null $navigationIcon = 'heroicon-o-key';

    protected static ?string $navigationLabel = 'Connections';

    protected static string|\UnitEnum|null $navigationGroup = 'Settings';

    // Superseded by the Setup steps (which deep-link this as the drill-down) — leaves the
    // sidebar when the new Setup menu is on; the route stays.
    public static function shouldRegisterNavigation(): bool
    {
        return ! config('launchpad.new_setup_enabled');
    }

    /** Menu-map family tag: setup-world editor (deep-linked from the new Setup steps). */
    public static function menuTag(): string
    {
        return 'setup';
    }

    protected static ?int $navigationSort = 6;

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('site.brand_name')->label('Tenant')->sortable(),
                TextColumn::make('provider')->badge(),
                TextColumn::make('credentials')
                    ->label('Credential (masked)')
                    ->state(fn (Connection $record): string => self::maskedSummary($record)),
                IconColumn::make('compromised')->boolean()
                    ->trueColor('danger')->falseColor('success'),
                TextColumn::make('last_rotated_at')->dateTime()->placeholder('never')->sortable(),
                TextColumn::make('rotation')
                    ->label('Gate')
                    ->badge()
                    ->state(fn (Connection $record): string => $record->needsRotation() ? 'needs rotation' : 'clean')
                    ->color(fn (string $state): string => $state === 'clean' ? 'success' : 'warning'),
            ])
            ->filters([
                SelectFilter::make('site_id')->label('Tenant')->relationship('site', 'brand_name'),
                SelectFilter::make('compromised')->options([1 => 'Compromised', 0 => 'Clean']),
            ])
            ->recordActions([
                self::revealAction(),
                self::reverifyAction(),
                self::rotateAction(),
            ]);
    }

    /**
     * "Re-verify" — ping the STORED credential against the push-capable endpoint and reconcile the chip
     * (report fix 3B). Green "verified" was pure connect-time state, never re-checked, so a revoked app
     * password showed clean and only 401'd at push. This flips it red the moment the credential dies.
     */
    private static function reverifyAction(): Action
    {
        return Action::make('reverify')
            ->label('Re-verify')
            ->icon('heroicon-o-shield-check')
            ->action(function (Connection $record): void {
                $ok = app(WordpressConnector::class)->reverify($record);

                if ($ok) {
                    Notification::make()->success()->title('Credential still valid')
                        ->body('The stored app password authenticated against the push endpoint.')->send();

                    return;
                }

                Notification::make()->danger()->title('Credential rejected — connection flagged')
                    ->body('WordPress refused the stored app password. The connection is now marked compromised; rotate it to restore publishing.')->send();
            });
    }

    /**
     * Manual WordPress app-password connection — the prerequisite for wiring a WP
     * instance the orchestrator pushes to. §1's connection entry was OAuth-oriented
     * (Google), so this is the manual path: enter base URL + WP username + app
     * password; it is verified against live WordPress before it is stored.
     */
    public static function connectWordPressAction(): Action
    {
        return Action::make('connectWordPress')
            ->label('Connect WordPress site')
            ->icon('heroicon-o-globe-alt')
            ->modalSubmitActionLabel('Verify & connect')
            ->schema([
                Select::make('site_id')
                    ->label('Tenant')
                    ->options(fn (): array => Site::query()->orderBy('brand_name')->pluck('brand_name', 'id')->all())
                    ->searchable()
                    ->required(),
                TextInput::make('base_url')
                    ->label('WordPress base URL')
                    ->url()
                    ->required()
                    ->placeholder('https://client-site.com')
                    ->helperText('The site root — the client appends /wp-json/…'),
                TextInput::make('username')
                    ->label('WP username')
                    ->required()
                    ->default('launchpad-sync'),
                TextInput::make('app_password')
                    ->label('Application password')
                    ->password()
                    ->revealable()
                    ->required()
                    ->helperText('Generated for the launchpad-sync user (provider = WordPress).'),
            ])
            ->action(function (array $data): void {
                try {
                    $connection = app(WordpressConnector::class)->connect((string) $data['site_id'], [
                        'base_url' => (string) $data['base_url'],
                        'username' => (string) $data['username'],
                        'app_password' => (string) $data['app_password'],
                    ]);
                } catch (Throwable $e) {
                    Notification::make()->danger()
                        ->title('Could not verify WordPress')
                        ->body($e->getMessage())
                        ->send();

                    return;
                }

                Notification::make()->success()
                    ->title('WordPress connected & verified')
                    ->body('Provider wp_app_password stored for '.($connection->credentials['base_url'] ?? '').'.')
                    ->send();
            });
    }

    /**
     * Connect the ONE shared platform Google account (GSC + GA4) — the "one email" the operator signs
     * in with once; every client then adds that email as a user on their property. Not per-tenant:
     * redirects to the platform OAuth consent. The label + color reflect the shared grant's state
     * (connect / connected / reconnect-needed).
     */
    public static function connectGoogleAction(): Action
    {
        $account = GoogleAccount::current();
        $needsReconnect = $account !== null && $account->needsReconnect();
        $connected = $account !== null && ! $needsReconnect;
        $label = $account?->label;

        return Action::make('connectGoogle')
            ->label($needsReconnect ? 'Reconnect Google — action needed' : ($connected ? 'Reconnect Google' : 'Connect Google (GSC + GA4)'))
            ->icon('heroicon-o-chart-bar-square')
            ->color($needsReconnect ? 'warning' : ($connected ? 'success' : 'primary'))
            ->url(route('google.authorize'))
            ->tooltip($connected && $label !== null && $label !== ''
                ? 'Shared platform account: '.$label.'. Clients add this email as a user on their GSC + GA4 property.'
                : 'One Google sign-in for the whole platform; each client adds it as a user on their property, then pick the property per tenant.');
    }

    /**
     * Point a tenant at WHICH GSC + GA4 property it reads from the shared account. The property
     * options are the full set the shared grant can see across ALL clients; the operator picks this
     * tenant's. Disabled until Google is connected. Selecting a tenant prefills its current pick.
     */
    public static function googlePropertiesAction(): Action
    {
        return Action::make('googleProperties')
            ->label('Set tenant Google properties')
            ->icon('heroicon-o-adjustments-horizontal')
            ->modalSubmitActionLabel('Save properties')
            ->disabled(fn (): bool => GoogleAccount::current() === null)
            ->modalDescription('Choose which Search Console + GA4 property this tenant reads from the shared Google account. The account must already be added as a user on each property in Google.')
            ->schema([
                Select::make('site_id')
                    ->label('Tenant')
                    ->options(fn (): array => Site::query()->orderBy('brand_name')->pluck('brand_name', 'id')->all())
                    ->searchable()
                    ->required()
                    ->live()
                    ->afterStateUpdated(function ($state, callable $set): void {
                        $site = is_string($state) ? Site::query()->find($state) : null;
                        $set('gsc_property', $site?->gsc_property);
                        $set('ga4_property', $site?->ga4_property);
                    }),
                Select::make('gsc_property')
                    ->label('Search Console property')
                    ->options(fn (): array => self::gscPropertyOptions())
                    ->searchable()
                    ->placeholder('— not connected —')
                    ->helperText('The GSC property the shared account can see for this client.'),
                Select::make('ga4_property')
                    ->label('GA4 property')
                    ->options(fn (): array => self::ga4PropertyOptions())
                    ->searchable()
                    ->placeholder('— not connected —')
                    ->helperText('The GA4 property the shared account can see for this client.'),
            ])
            ->action(function (array $data): void {
                $site = Site::query()->find((string) $data['site_id']);
                if ($site === null) {
                    return;
                }

                app(GoogleConnectionService::class)->setSiteProperties(
                    $site,
                    isset($data['gsc_property']) ? (string) $data['gsc_property'] : null,
                    isset($data['ga4_property']) ? (string) $data['ga4_property'] : null,
                );

                Notification::make()->success()
                    ->title('Google properties saved')
                    ->body($site->brand_name.' now reads GSC '.($data['gsc_property'] ?: '—').' / GA4 '.($data['ga4_property'] ?: '—').'.')
                    ->send();
            });
    }

    /**
     * GSC properties visible to the shared grant, as select options. A failed/absent grant yields an
     * empty list (the picker shows the placeholder) rather than crashing the modal.
     *
     * @return array<string, string>
     */
    private static function gscPropertyOptions(): array
    {
        $account = GoogleAccount::current();
        if ($account === null) {
            return [];
        }

        try {
            $sites = app(GoogleConnectionService::class)->listGscSites($account);
        } catch (GoogleException) {
            return [];
        }

        return array_combine($sites, $sites) ?: [];
    }

    /**
     * GA4 properties visible to the shared grant, as select options (label → "Name (properties/123)").
     *
     * @return array<string, string>
     */
    private static function ga4PropertyOptions(): array
    {
        $account = GoogleAccount::current();
        if ($account === null) {
            return [];
        }

        try {
            $properties = app(GoogleConnectionService::class)->listGa4Properties($account);
        } catch (GoogleException) {
            return [];
        }

        $options = [];
        foreach ($properties as $property) {
            $options[$property['property']] = $property['displayName'] !== ''
                ? $property['displayName'].' ('.$property['property'].')'
                : $property['property'];
        }

        return $options;
    }

    private static function maskedSummary(Connection $connection): string
    {
        $masked = app(CredentialMasker::class)->maskArray($connection->credentials ?? []);

        $parts = [];
        foreach ($masked as $key => $value) {
            $parts[] = is_scalar($value) ? "{$key}: {$value}" : (string) $key;
        }

        return $parts === [] ? '—' : implode(' · ', $parts);
    }

    private static function revealAction(): Action
    {
        return Action::make('reveal')
            ->icon('heroicon-o-eye')
            ->requiresConfirmation()
            ->modalDescription('Revealing the plaintext credential is audited.')
            ->action(function (Connection $record): void {
                /** @var User $user */
                $user = Auth::user();
                $credentials = app(CredentialRevealer::class)->reveal($record, $user);

                $lines = [];
                foreach ($credentials as $key => $value) {
                    $lines[] = is_scalar($value) ? "{$key}: {$value}" : (string) $key;
                }

                Notification::make()->title('Credential revealed (audited)')
                    ->body(implode("\n", $lines))->warning()->persistent()->send();
            });
    }

    private static function rotateAction(): Action
    {
        return Action::make('rotate')
            ->icon('heroicon-o-arrow-path')
            ->color('warning')
            ->schema([
                KeyValue::make('credentials')->label('New credentials')
                    ->keyLabel('Field')->valueLabel('Value')->required(),
            ])
            ->action(function (Connection $record, array $data): void {
                $credentials = is_array($data['credentials'] ?? null) ? $data['credentials'] : [];

                $result = app(ConnectionRotator::class)->rotate($record, $credentials, Auth::id());

                if ($result->ok) {
                    Notification::make()->success()->title('Rotated and verified')->send();

                    return;
                }

                Notification::make()->danger()->title('Rotation failed')->body($result->message)->send();
            });
    }

    /**
     * @return array<string, PageRegistration>
     */
    public static function getPages(): array
    {
        return [
            'index' => ListConnections::route('/'),
        ];
    }
}
