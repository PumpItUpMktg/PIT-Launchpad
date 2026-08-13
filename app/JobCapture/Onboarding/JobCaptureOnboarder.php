<?php

namespace App\JobCapture\Onboarding;

use App\Enums\ProductPlan;
use App\Enums\SiteStatus;
use App\JobCapture\Auth\TechProvisioner;
use App\Models\Account;
use App\Models\Site;
use App\Models\TechDevice;
use App\Onboarding\WordpressPrep;

/**
 * The standalone Job Capture onboarding path — stands up a client who only runs the field-tech capture →
 * WordPress publish loop, without the full Launchpad intake. It deliberately REUSES the shared pieces so a
 * later upgrade is a flag flip, not a rebuild: {@see WordpressPrep} is the same verified WP-connection
 * process Launchpad's guided flow uses, and {@see TechProvisioner} is the same tech identity + invite path.
 *
 * The only Job-Capture-specific bits are the plan stamp ({@see ProductPlan::JobCapture}) and activating on a
 * connected WordPress — a Job Capture tenant needs no services / silos / plan to publish jobs, so it goes
 * Active the moment WordPress is connected (publish works from Active; Live is the later client-handover).
 */
final class JobCaptureOnboarder
{
    public function __construct(
        private readonly WordpressPrep $wordpress,
        private readonly TechProvisioner $techs,
    ) {}

    /** Create the tenant (Account + Site) stamped as a standalone Job Capture client, still in onboarding. */
    public function createClient(string $accountName, string $brandName, ?string $domainUrl = null): Site
    {
        $accountName = trim($accountName) !== '' ? trim($accountName) : trim($brandName);
        $account = Account::create(['name' => $accountName, 'type' => 'direct']);

        return Site::create([
            'account_id' => $account->id,
            'brand_name' => trim($brandName),
            'domain_url' => $domainUrl !== null && trim($domainUrl) !== '' ? trim($domainUrl) : null,
            'status' => SiteStatus::Onboarding,
            'product' => ProductPlan::JobCapture,
        ]);
    }

    /**
     * Connect + verify + prep WordPress via the SHARED process (verify-before-store, then the stubbed
     * plugin/theme installs). Returns the per-step checklist; `ready` is true only when every step is green.
     *
     * @param  array{base_url: string, username: string, app_password: string}  $credentials
     * @return array{ready: bool, steps: array<string, bool>, error: string|null}
     */
    public function connectWordpress(Site $site, array $credentials): array
    {
        return $this->wordpress->prep($site, $credentials);
    }

    /**
     * Onboard a field tech (role=tech User + capture device + invite) via the shared provisioner.
     *
     * @return array{device: TechDevice, code: string, link: string, delivered: bool}
     */
    public function addTech(Site $site, string $name, ?string $email = null, ?string $phone = null): array
    {
        return $this->techs->provision($site->id, $name, $phone, $email);
    }

    /** A Job Capture tenant is ready to go Active once WordPress is connected — that's all jobs need. */
    public function canActivate(Site $site): bool
    {
        return $this->wordpress->isConnected($site);
    }

    /** Flip a connected Job Capture tenant to Active so its jobs can publish. Returns false if not ready. */
    public function activate(Site $site): bool
    {
        if (! $this->canActivate($site)) {
            return false;
        }

        $site->forceFill(['status' => SiteStatus::Active])->save();

        return true;
    }
}
