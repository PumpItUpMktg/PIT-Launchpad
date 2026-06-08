<?php

namespace App\Operator\Handover;

use App\Enums\ConnectionProvider;
use App\Models\Connection;
use App\Models\Scopes\SiteScope;
use App\Models\Site;
use App\Security\ConnectionRotator;
use App\Security\GateResult;
use App\Security\SiteLauncher;
use App\Security\SiteLaunchGate;

/**
 * The site-handover orchestration (→ Live). Handover is the client-handover
 * milestone, not a publish switch — the engine has been publishing into the
 * blank instance since Active. Live locks in credential hygiene and hands the
 * finished site over.
 *
 * THE INVARIANT: every path to Live routes through §9's SiteLauncher (the sole
 * writer of SiteStatus::Live) — this class never writes Live directly — so the
 * SiteLaunchGate always runs and blocks Live until every credential is rotated /
 * non-compromised and the platform attestation is complete.
 *
 *  - stays-on-our-hosting: the blank instance IS the live instance — gate → Live,
 *    same Connection continues.
 *  - migrate-to-client-hosting: the operator performs the Duplicator move (WP-side,
 *    not automated here); this re-points the Connection to the new URL + fresh app
 *    password, VERIFIES it against the new host (the re-point is a §9 rotation:
 *    verify-before-revoke), then gate → Live and the engine resumes there.
 */
class SiteHandover
{
    public function __construct(
        private readonly SiteLauncher $launcher,
        private readonly SiteLaunchGate $gate,
        private readonly ConnectionRotator $rotator,
    ) {}

    /**
     * The gate checklist for the red-until-green handover UI (no writes).
     */
    public function gate(Site $site): GateResult
    {
        return $this->gate->check($site);
    }

    /**
     * Stays-on-our-hosting handover: no re-point. Routes straight through the
     * §9 gate; a failing gate leaves the site untouched and returns the checklist.
     */
    public function handoverStaying(Site $site, ?string $actorId = null): HandoverResult
    {
        $result = $this->launcher->launch($site, $actorId);

        return new HandoverResult(
            launched: $result->passed,
            repointed: false,
            gateResult: $result,
            message: $result->passed
                ? 'Handed over on our hosting; site marked Live.'
                : 'Blocked by the launch gate — clear the checklist first.',
        );
    }

    /**
     * Migrate-to-client-hosting handover: re-point the WP Connection to the new
     * host (new URL + fresh app password), verify it before committing, then gate
     * → Live. A failed verification aborts before any Live write.
     */
    public function handoverMigrating(
        Site $site,
        string $newUrl,
        string $newAppPassword,
        ?string $username = null,
        ?string $actorId = null,
    ): HandoverResult {
        $connection = $this->wordpressConnection($site);

        if ($connection === null) {
            return new HandoverResult(false, false, null, 'No WordPress connection to re-point.');
        }

        $existing = $connection->credentials ?? [];
        $credentials = [
            'base_url' => $newUrl,
            'username' => $username ?? (string) ($existing['username'] ?? ''),
            'app_password' => $newAppPassword,
        ];

        // The re-point is a §9 rotation: verify the new host BEFORE committing,
        // then store the credential, clear compromised, and stamp last_rotated_at.
        $rotation = $this->rotator->rotate($connection, $credentials, $actorId);

        if (! $rotation->ok) {
            return new HandoverResult(false, false, null, 'Re-point failed verification: '.$rotation->message);
        }

        $result = $this->launcher->launch($site, $actorId);

        return new HandoverResult(
            launched: $result->passed,
            repointed: true,
            gateResult: $result,
            message: $result->passed
                ? 'Re-pointed to the client host and handed over; site marked Live.'
                : 'Re-pointed, but blocked by the launch gate — clear the checklist first.',
        );
    }

    private function wordpressConnection(Site $site): ?Connection
    {
        return Connection::withoutGlobalScope(SiteScope::class)
            ->where('site_id', $site->id)
            ->where('provider', ConnectionProvider::WpAppPassword->value)
            ->first();
    }
}
