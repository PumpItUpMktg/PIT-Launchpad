<?php

namespace App\Security;

use App\Enums\AuditAction;
use App\Models\Connection;
use App\Security\Verification\ConnectionVerifier;

/**
 * No-downtime per-tenant credential rotation: rotate → verify → revoke. The new
 * credential is verified with a live provider call BEFORE the old one is
 * replaced, so a live tenant never breaks mid-rotation. Only on a verified swap
 * are last_rotated_at stamped and the compromised flag cleared; a failed
 * verification leaves the stored credential completely untouched.
 */
class ConnectionRotator
{
    public function __construct(
        private readonly ConnectionVerifier $verifier,
        private readonly Audit $audit,
    ) {}

    /**
     * @param  array<string, mixed>  $newCredentials
     */
    public function rotate(Connection $connection, array $newCredentials, ?string $actorId = null): RotationResult
    {
        // Verify the candidate BEFORE revoking — nothing is written until the
        // new credential is proven to work.
        if (! $this->verifier->verify($connection, $newCredentials)) {
            return RotationResult::failed(
                'Verification of the new credential failed; the existing credential was left untouched.'
            );
        }

        $connection->credentials = $newCredentials;
        $connection->last_rotated_at = now();
        $connection->compromised = false;
        $connection->compromised_reason = null;
        $connection->save();

        $this->audit->log(AuditAction::CredentialRotated, $connection, $actorId, [
            'provider' => $connection->provider->value,
        ]);

        return RotationResult::success($connection);
    }
}
