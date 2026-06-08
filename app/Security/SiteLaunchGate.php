<?php

namespace App\Security;

use App\Enums\PlatformSecret;
use App\Models\Connection;
use App\Models\PlatformSecretRotation;
use App\Models\Scopes\SiteScope;
use App\Models\Site;

/**
 * The pre-client rotation gate — the headline of §9. A site cannot go live while
 * any of its connection credentials are compromised or unrotated-since-exposure,
 * or while any shared platform secret lacks its post-pilot rotation attestation.
 *
 * `check()` is pure (no writes) and returns a structured checklist so the launch
 * flow can block and the admin can render it red until green.
 */
class SiteLaunchGate
{
    public function check(Site $site): GateResult
    {
        return GateResult::fromChecks([
            ...$this->connectionChecks($site),
            ...$this->platformChecks(),
        ]);
    }

    public function passes(Site $site): bool
    {
        return $this->check($site)->passed;
    }

    /**
     * @return list<GateCheck>
     */
    private function connectionChecks(Site $site): array
    {
        // Read the tenant's connections directly by site_id, independent of the
        // request-lifetime current-site singleton.
        $connections = Connection::withoutGlobalScope(SiteScope::class)
            ->where('site_id', $site->id)
            ->orderBy('provider')
            ->get();

        $checks = [];
        foreach ($connections as $connection) {
            $key = 'connection:'.$connection->provider->value;
            $label = $connection->provider->value;

            $checks[] = $connection->needsRotation()
                ? GateCheck::fail($key, $label, $this->reason($connection))
                : GateCheck::pass($key, $label);
        }

        return $checks;
    }

    /**
     * @return list<GateCheck>
     */
    private function platformChecks(): array
    {
        $attested = PlatformSecretRotation::query()
            ->get()
            ->map(fn (PlatformSecretRotation $r) => $r->platform_secret)
            ->all();

        $checks = [];
        foreach (PlatformSecret::requiredForLaunch() as $secret) {
            $key = 'platform:'.$secret->value;

            $checks[] = in_array($secret, $attested, true)
                ? GateCheck::pass($key, $secret->label())
                : GateCheck::fail($key, $secret->label(), 'No post-pilot rotation attestation on record.');
        }

        return $checks;
    }

    private function reason(Connection $connection): string
    {
        if ($connection->compromised) {
            return $connection->compromised_reason
                ? 'Credential is compromised: '.$connection->compromised_reason
                : 'Credential is flagged compromised and must be rotated.';
        }

        if ($connection->last_rotated_at === null) {
            return 'Credential has never been rotated.';
        }

        return 'Credential was last rotated before it was exposed.';
    }
}
