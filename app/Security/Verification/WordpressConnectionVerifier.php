<?php

namespace App\Security\Verification;

use App\Enums\ConnectionProvider;
use App\Integrations\Wordpress\WordpressClientFactory;
use App\Models\Connection;
use App\Models\Site;
use Throwable;

/**
 * The live verifier behind §9's rotation gate. For a WordPress app-password
 * connection it pings live WordPress with the candidate credential (so
 * verify-before-revoke actually proves the new secret works before the old is
 * dropped). Other providers are accepted permissively until their own adapters
 * land (e.g. GBP arrives with the GBP integration).
 */
class WordpressConnectionVerifier implements ConnectionVerifier
{
    public function __construct(
        private readonly WordpressClientFactory $factory,
    ) {}

    public function verify(Connection $connection, array $candidateCredentials): bool
    {
        if ($connection->provider !== ConnectionProvider::WpAppPassword) {
            return $candidateCredentials !== [];
        }

        if ($candidateCredentials === []) {
            return false;
        }

        try {
            $existing = $connection->credentials ?? [];
            $credentials = array_merge(
                ['base_url' => $existing['base_url'] ?? null],
                $candidateCredentials,
            );

            $site = Site::find($connection->site_id);

            return $this->factory->usingCredentials($credentials, $site)->ping();
        } catch (Throwable) {
            return false;
        }
    }
}
