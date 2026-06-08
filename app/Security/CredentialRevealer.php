<?php

namespace App\Security;

use App\Enums\AuditAction;
use App\Models\Connection;
use App\Models\User;
use Illuminate\Auth\Access\AuthorizationException;

/**
 * The single, audited path to a plaintext credential. Reveal is an explicit
 * operator action gated by the ConnectionPolicy; every reveal writes a
 * CredentialRevealed audit row (who, when, which connection — never the secret
 * itself). Everywhere else, credentials are masked.
 */
class CredentialRevealer
{
    public function __construct(
        private readonly Audit $audit,
    ) {}

    /**
     * @return array<string, mixed>
     *
     * @throws AuthorizationException
     */
    public function reveal(Connection $connection, User $actor): array
    {
        if ($actor->cannot('reveal', $connection)) {
            throw new AuthorizationException('Only operators may reveal credentials.');
        }

        $this->audit->log(AuditAction::CredentialRevealed, $connection, $actor->id, [
            'provider' => $connection->provider->value,
        ]);

        return $connection->credentials ?? [];
    }
}
