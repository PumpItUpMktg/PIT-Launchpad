<?php

namespace App\Security\Verification;

use App\Models\Connection;

/**
 * The live-test seam for a credential rotation: given a connection and a
 * candidate (new) credential set, make a real provider call — a WP ping, a GBP
 * token check — and report whether the new credential works. Rotation verifies
 * with this BEFORE revoking the old one, so a live tenant never breaks.
 *
 * Vendor-deferred: the default binding is a mock; the real per-provider adapters
 * (which reuse the companion-plugin / OAuth clients) bind here later with no
 * change to the rotation flow.
 */
interface ConnectionVerifier
{
    /**
     * @param  array<string, mixed>  $candidateCredentials
     */
    public function verify(Connection $connection, array $candidateCredentials): bool;
}
