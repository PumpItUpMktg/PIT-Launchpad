<?php

namespace App\Filament\Resources;

use App\Filament\Resources\ConnectionsResource\Pages\ListConnections;
use App\Models\Connection;
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

    protected static string|\UnitEnum|null $navigationGroup = 'Controls';

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
                self::rotateAction(),
            ]);
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
