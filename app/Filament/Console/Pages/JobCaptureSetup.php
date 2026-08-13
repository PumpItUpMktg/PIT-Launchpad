<?php

namespace App\Filament\Console\Pages;

use App\JobCapture\Onboarding\JobCaptureOnboarder;
use App\Models\Site;
use App\Models\User;
use App\Onboarding\WordpressPrep;
use App\Security\Capability;
use BackedEnum;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Illuminate\Support\Facades\Auth;

/**
 * Console → Jobs → New Job Capture Client: the standalone Job Capture onboarding path. One guided page that
 * stands up a client end to end — create the tenant, connect their WordPress through the SHARED verified
 * process, add field techs (auto-invited), then activate so jobs can publish. Thin over
 * {@see JobCaptureOnboarder}; Super-Admin (tenant-lifecycle) only. A Job Capture tenant lands in the exact
 * shape Launchpad expects, so upgrading later is flipping the plan, not re-onboarding.
 *
 * @property-read Site|null $site
 * @property-read array<string, bool> $checklist
 * @property-read bool $connected
 */
class JobCaptureSetup extends Page
{
    protected static string|BackedEnum|null $navigationIcon = 'heroicon-o-rocket-launch';

    protected static ?string $navigationLabel = 'New Job Capture Client';

    protected static string|\UnitEnum|null $navigationGroup = 'Jobs';

    protected static ?int $navigationSort = 5;

    protected static ?string $slug = 'job-capture-setup';

    protected string $view = 'filament.console.job-capture-setup';

    /** The client being onboarded (persisted in session so a refresh resumes mid-flow). */
    public ?string $siteId = null;

    // Create-client form.
    public string $newAccountName = '';

    public string $newBrandName = '';

    public string $newDomain = '';

    // Connect-WordPress form.
    public string $baseUrl = '';

    public string $username = '';

    public string $appPassword = '';

    // Add-tech form.
    public string $techName = '';

    public string $techEmail = '';

    public string $techPhone = '';

    /**
     * The just-issued capture credential, shown once.
     *
     * @var array{name: string, link: string, code: string}|null
     */
    public ?array $lastIssued = null;

    /** Standing up a tenant is a Super-Admin (tenant-lifecycle) action. */
    public static function canAccess(): bool
    {
        $user = Auth::user();

        return $user instanceof User && $user->hasCapability(Capability::ManageTenantLifecycle);
    }

    public function mount(): void
    {
        $this->siteId = session('job_capture_onboard_site_id');
    }

    public function getSiteProperty(): ?Site
    {
        if ($this->siteId === null) {
            return null;
        }

        $site = Site::withoutGlobalScopes()->find($this->siteId);

        return $site !== null && $this->user()->canSeeSite((string) $site->id) ? $site : null;
    }

    /** @return array<string, bool> */
    public function getChecklistProperty(): array
    {
        $site = $this->site;

        return $site === null ? [] : app(WordpressPrep::class)->status($site);
    }

    public function getConnectedProperty(): bool
    {
        $site = $this->site;

        return $site !== null && app(WordpressPrep::class)->isConnected($site);
    }

    /** Step 1 — create the tenant, stamped as a standalone Job Capture client. */
    public function createClient(): void
    {
        if (! self::canAccess()) {
            return;
        }
        if (trim($this->newBrandName) === '') {
            Notification::make()->title('Enter the client’s business name.')->warning()->send();

            return;
        }

        $site = app(JobCaptureOnboarder::class)->createClient($this->newAccountName, $this->newBrandName, $this->newDomain ?: null);

        $this->siteId = $site->id;
        session(['job_capture_onboard_site_id' => $site->id]);
        $this->baseUrl = (string) ($site->domain_url ?? '');
        $this->newAccountName = $this->newBrandName = $this->newDomain = '';

        Notification::make()->title("Created {$site->brand_name} — now connect their WordPress.")->success()->send();
    }

    /** Step 2 — connect WordPress through the shared verified process. */
    public function connectWordpress(): void
    {
        $site = $this->requireSite();
        if ($site === null) {
            return;
        }
        if (trim($this->baseUrl) === '' || trim($this->username) === '' || trim($this->appPassword) === '') {
            Notification::make()->title('Enter the site URL, username, and application password.')->warning()->send();

            return;
        }

        $result = app(JobCaptureOnboarder::class)->connectWordpress($site, [
            'base_url' => $this->baseUrl,
            'username' => $this->username,
            'app_password' => $this->appPassword,
        ]);

        $this->appPassword = ''; // never keep the secret in component state

        if (! $result['ready']) {
            Notification::make()->title('Could not connect')->body((string) $result['error'])->danger()->send();

            return;
        }

        Notification::make()->title('WordPress connected & verified.')->success()->send();
    }

    /** Step 3 — onboard a field tech (auto-invited by email when supplied). */
    public function addTech(): void
    {
        $site = $this->requireSite();
        if ($site === null) {
            return;
        }
        if (trim($this->techName) === '') {
            Notification::make()->title('Enter the tech’s name.')->warning()->send();

            return;
        }

        $email = trim($this->techEmail) ?: null;
        $result = app(JobCaptureOnboarder::class)->addTech($site, trim($this->techName), $email, trim($this->techPhone) ?: null);

        $this->lastIssued = [
            'name' => trim($this->techName),
            'link' => $result['link'],
            'code' => $result['code'],
        ];
        $this->techName = $this->techEmail = $this->techPhone = '';

        if ($result['delivered'] && $email !== null) {
            Notification::make()->title("Emailed the capture invite to {$email}.")->success()->send();
        } else {
            Notification::make()->title('Tech added — send them the link + code below.')->warning()->send();
        }
    }

    public function dismissIssued(): void
    {
        $this->lastIssued = null;
    }

    /** Step 4 — activate the tenant so its jobs can publish, then finish. */
    public function activate(): void
    {
        $site = $this->requireSite();
        if ($site === null) {
            return;
        }

        if (! app(JobCaptureOnboarder::class)->activate($site)) {
            Notification::make()->title('Connect WordPress before activating.')->warning()->send();

            return;
        }

        Notification::make()->title("{$site->brand_name} is active — jobs will publish to their site.")->success()->send();
        $this->reset('siteId');
        session()->forget('job_capture_onboard_site_id');
    }

    private function requireSite(): ?Site
    {
        if (! self::canAccess()) {
            return null;
        }

        return $this->site;
    }

    private function user(): User
    {
        /** @var User $user */
        $user = Auth::user();

        return $user;
    }
}
