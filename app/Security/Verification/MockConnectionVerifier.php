<?php

namespace App\Security\Verification;

use App\Models\Connection;

/**
 * Default (vendor-deferred) verifier. Treats a non-empty candidate credential as
 * verifiable so the rotation flow is exercisable end-to-end without a live
 * provider call. Real adapters replace this binding in a later section.
 */
class MockConnectionVerifier implements ConnectionVerifier
{
    public function verify(Connection $connection, array $candidateCredentials): bool
    {
        return $candidateCredentials !== [];
    }
}
