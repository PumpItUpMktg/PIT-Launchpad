<?php

namespace Tests\Support;

use App\Models\Connection;
use App\Security\Verification\ConnectionVerifier;

/**
 * A verifier whose result is fixed by the test, so verify-before-revoke can be
 * exercised in both directions without a live provider call.
 */
class StubConnectionVerifier implements ConnectionVerifier
{
    public int $calls = 0;

    public function __construct(private readonly bool $result) {}

    public function verify(Connection $connection, array $candidateCredentials): bool
    {
        $this->calls++;

        return $this->result;
    }
}
