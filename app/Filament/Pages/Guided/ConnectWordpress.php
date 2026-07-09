<?php

namespace App\Filament\Pages\Guided;

use App\Enums\SetupStep;
use App\Guided\GuidedPage;
use App\Guided\StepGate;
use App\Onboarding\WordpressPrep;
use Filament\Notifications\Notification;

/**
 * Step 2 · Connect WordPress — the WP-prep phase. Connect (URL + username + application password)
 * → verify → install companion plugin → install the Launchpad block theme → clean up ({@see WordpressPrep};
 * connect/verify real, installs stubbed). Gates on all green: `deps_ready` is the completion flag,
 * so Brand (step 3) — which pushes the brand kit — can't be reached until WordPress is prepped.
 *
 * @property-read array<string, bool> $checklist
 * @property-read bool $ready
 */
class ConnectWordpress extends GuidedPage
{
    protected static ?string $slug = 'setup/connect';

    protected static ?int $navigationSort = 2;

    protected static ?string $navigationLabel = 'Connect WordPress';

    protected string $view = 'filament.guided.connect-wordpress';

    public string $baseUrl = '';

    public string $username = '';

    public string $appPassword = '';

    public function step(): SetupStep
    {
        return SetupStep::ConnectWordpress;
    }

    public function mount(): void
    {
        parent::mount();

        $site = $this->getSite();
        if ($site !== null && $this->baseUrl === '') {
            $this->baseUrl = (string) ($site->domain_url ?? '');
        }
    }

    /** @return array<string, bool> */
    public function getChecklistProperty(): array
    {
        $site = $this->getSite();

        return $site === null ? [] : app(WordpressPrep::class)->status($site);
    }

    public function getReadyProperty(): bool
    {
        $site = $this->getSite();

        return $site !== null && app(StepGate::class)->state($site)->deps_ready;
    }

    /** Connect + verify + prep (installs stubbed). On all-green, sets deps_ready. */
    public function connectAndPrep(): void
    {
        $site = $this->getSite();
        if ($site === null) {
            return;
        }

        if (trim($this->baseUrl) === '' || trim($this->username) === '' || trim($this->appPassword) === '') {
            Notification::make()->title('Enter your site URL, username, and application password.')->warning()->send();

            return;
        }

        $result = app(WordpressPrep::class)->prep($site, [
            'base_url' => $this->baseUrl,
            'username' => $this->username,
            'app_password' => $this->appPassword,
        ]);

        if (! $result['ready']) {
            Notification::make()->title('Could not connect')->body((string) $result['error'])->danger()->send();

            return;
        }

        app(StepGate::class)->state($site)->update(['deps_ready' => true]);
        $this->appPassword = ''; // don't keep the secret in component state
        Notification::make()->title('WordPress connected & prepped.')->success()->send();
    }

    public function proceed(): void
    {
        $site = $this->getSite();
        if ($site === null) {
            return;
        }

        if (! $this->ready) {
            Notification::make()->title('Connect & prep WordPress first.')->warning()->send();

            return;
        }

        $gate = app(StepGate::class);
        $gate->complete($gate->state($site), SetupStep::ConnectWordpress);
        $this->redirect(SetupStep::Brand->pageClass()::getUrl());
    }
}
