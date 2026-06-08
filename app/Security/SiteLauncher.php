<?php

namespace App\Security;

use App\Enums\AuditAction;
use App\Enums\SiteStatus;
use App\Models\Site;

/**
 * The guarded go-live transition. It is the one place a site flips to Live, and
 * it does so only when the SiteLaunchGate passes — so an unrotated credential or
 * a missing platform attestation hard-blocks launch. A successful launch writes
 * a SiteWentLive audit row; a blocked one leaves the site untouched and returns
 * the failing checklist for the caller to surface.
 */
class SiteLauncher
{
    public function __construct(
        private readonly SiteLaunchGate $gate,
        private readonly Audit $audit,
    ) {}

    public function launch(Site $site, ?string $actorId = null): GateResult
    {
        $result = $this->gate->check($site);

        if (! $result->passed) {
            return $result;
        }

        $site->status = SiteStatus::Live;
        $site->save();

        $this->audit->log(AuditAction::SiteWentLive, $site, $actorId);

        return $result;
    }
}
