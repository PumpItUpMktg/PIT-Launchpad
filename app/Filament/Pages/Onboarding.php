<?php

namespace App\Filament\Pages;

use App\Enums\UserRole;
use App\Enums\WizardStep;
use App\Models\Account;
use App\Onboarding\IncompleteOnboardingException;
use App\Onboarding\IntakeCollector;
use App\Onboarding\OnboardingWizard;
use BackedEnum;
use Filament\Forms\Components\Repeater;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Filament\Schemas\Components\Text;
use Filament\Schemas\Components\Wizard;
use Filament\Schemas\Components\Wizard\Step;
use Filament\Schemas\Schema;

/**
 * The onboarding wizard surface (operator admin). A resumable, role-aware
 * multi-step form that collects the full intake into §1 entities via the
 * onboarding services. Step 9 (silo selection) is a wired placeholder gated on
 * §4 + §6a.
 *
 * @property-read Schema $form
 */
class Onboarding extends Page
{
    protected static string|BackedEnum|null $navigationIcon = 'heroicon-o-rocket-launch';

    protected static ?string $navigationLabel = 'Onboarding';

    /** Menu-map family tag: not yet placed in the new Setup/Operate structure — cutover decision pending. */
    public static function menuTag(): string
    {
        return 'unaddressed';
    }

    protected string $view = 'filament.pages.onboarding';

    /**
     * Disabled for now — a deliberate "don't walk here yet" gate, not a deletion.
     * This 9-step §7a intake is the richer net-new-business flow, but its
     * WordPress-connection step is still stubbed: it writes an app_password-only,
     * username-less, UNVERIFIED, compromised=true connection that dead-ends at
     * publish (see the filed bug). The hardened Create Site wizard (Portfolio →
     * New site) is the canonical create path until the "unify onboarding" slice
     * ports the verified connection (username + Test connection + three-key
     * verify-before-store) into here.
     *
     * canAccess() gates BOTH the nav entry and the route (a direct URL 403s), so
     * the broken door can't be walked at all. The form/submit logic is untouched,
     * so that slice re-enables this by flipping the flag back.
     */
    public static function canAccess(): bool
    {
        return false;
    }

    public static function shouldRegisterNavigation(): bool
    {
        return false;
    }

    /** @var array<string, mixed> */
    public array $data = [];

    public function mount(): void
    {
        $this->form->fill();
    }

    public function form(Schema $schema): Schema
    {
        return $schema
            ->statePath('data')
            ->components([
                Wizard::make([
                    Step::make(WizardStep::Account->label())->schema([
                        TextInput::make('brand_name')->required(),
                        TextInput::make('domain_url')->url(),
                        TextInput::make('wp_app_password')->label('WordPress application password')->password(),
                    ]),
                    Step::make(WizardStep::Identity->label())->schema([
                        TextInput::make('palette_primary')->label('Primary colour')->default('#0F62FE'),
                        TextInput::make('phone'),
                        Toggle::make('is_storefront'),
                    ]),
                    Step::make(WizardStep::ServiceCatalog->label())->schema([
                        Select::make('primary_category')
                            ->options(['plumber' => 'Plumber', 'hvac_contractor' => 'HVAC', 'electrician' => 'Electrician'])
                            ->helperText('Seeds the GBP service-type checklist.'),
                        Repeater::make('services')->schema([
                            TextInput::make('name')->required(),
                            Select::make('silo_role')->options(['pillar' => 'Pillar', 'supporting' => 'Supporting'])->default('supporting'),
                            Toggle::make('is_most_profitable'),
                            Toggle::make('is_growth_priority'),
                        ])->defaultItems(1),
                    ]),
                    Step::make(WizardStep::Markets->label())->schema([
                        Repeater::make('markets')->schema([
                            TextInput::make('name')->required(),
                            TextInput::make('geo_id'),
                            Select::make('tier')->options(['priority' => 'Priority', 'coverage' => 'Coverage'])->default('coverage'),
                        ])->defaultItems(1),
                    ]),
                    Step::make(WizardStep::Proof->label())->schema([
                        Repeater::make('proof')->schema([
                            Select::make('type')->options(['warranty' => 'Warranty', 'guarantee' => 'Guarantee', 'license' => 'License', 'cert' => 'Certification', 'review_aggregate' => 'Reviews'])->required(),
                            Toggle::make('is_substantiated'),
                        ])->defaultItems(1),
                    ]),
                    Step::make(WizardStep::Targets->label())->schema([
                        Repeater::make('keywords')->simple(TextInput::make('query')->required()),
                        TextInput::make('primary_actions')->helperText('Comma-separated CTAs, e.g. call, book'),
                    ]),
                    Step::make(WizardStep::Assets->label())->schema([
                        Repeater::make('redirects')->schema([
                            TextInput::make('from_url')->required(),
                            TextInput::make('to_url')->required(),
                        ])->defaultItems(0),
                    ]),
                    Step::make(WizardStep::Voice->label())->schema([
                        TextInput::make('identity')->label('Brand identity'),
                        TextInput::make('warmth')->numeric()->default(0.7),
                    ]),
                    Step::make(WizardStep::SiloSelection->label())->schema([
                        Text::make('Silo selection runs after §4 auto-propose + §6 backfill — available in a later relay.'),
                    ]),
                ]),
            ]);
    }

    public function submit(): void
    {
        $data = $this->form->getState();

        $collector = app(IntakeCollector::class);
        $wizard = app(OnboardingWizard::class);

        $account = Account::create(['name' => $data['brand_name'] ?? 'New Client', 'type' => 'direct']);
        $site = $collector->createSite($account, [
            'brand_name' => $data['brand_name'] ?? 'New Client',
            'domain_url' => $data['domain_url'] ?? null,
        ]);

        if (! empty($data['wp_app_password'])) {
            $collector->storeWordPressCredential($site, ['app_password' => $data['wp_app_password']]);
        }

        $collector->saveIdentity($site,
            ['palette' => ['primary' => $data['palette_primary'] ?? null]],
            ['phone' => $data['phone'] ?? null, 'is_storefront' => (bool) ($data['is_storefront'] ?? false)],
        );

        $collector->saveServiceCatalog($site, $data['services'] ?? []);
        $collector->saveMarkets($site, $data['markets'] ?? []);
        $collector->saveProof($site, $data['proof'] ?? []);
        $collector->saveTargets($site, [
            'keywords' => array_map(fn ($k) => is_array($k) ? ($k['query'] ?? '') : $k, $data['keywords'] ?? []),
            'conversion' => ['primary_actions' => array_values(array_filter(array_map('trim', explode(',', (string) ($data['primary_actions'] ?? '')))))],
        ]);
        $collector->saveAssets($site, ['redirects' => $data['redirects'] ?? []]);

        if (! empty($data['identity'])) {
            $voice = $collector->synthesizeVoice($site, ['identity' => $data['identity'], 'warmth' => $data['warmth'] ?? 0.7]);
            $collector->activateVoice($voice);
        }

        foreach ([WizardStep::Account, WizardStep::Identity, WizardStep::ServiceCatalog, WizardStep::Markets, WizardStep::Proof, WizardStep::Targets, WizardStep::Assets, WizardStep::Voice] as $step) {
            $wizard->completeStep($site, $step, UserRole::Operator);
        }

        try {
            $wizard->launch($site, UserRole::Operator);
            Notification::make()->success()->title('Tenant launched')->send();
        } catch (IncompleteOnboardingException $e) {
            Notification::make()->warning()->title('Intake saved — not yet launchable')
                ->body('Missing: '.implode(', ', $e->missing))->send();
        }
    }
}
