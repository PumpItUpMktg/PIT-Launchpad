<?php

namespace App\Operator\Controls;

use App\Enums\ConnectionProvider;
use App\Integrations\Wordpress\WordpressClientFactory;
use App\Integrations\Wordpress\WordpressException;
use App\Models\Connection;
use App\Models\Scopes\SiteScope;
use App\Models\Site;

/**
 * Establishes a per-site manual WordPress app-password connection — the §1
 * Connection entry was OAuth-oriented (Google), so this is the manual path that
 * pairs with the launch orchestrator. Verify-before-store: the credential is
 * pinged against live WordPress (wp/v2/users/me) and only persisted on a 2xx, so
 * a broken credential never lands in the vault. A freshly verified manual entry
 * is stored clean (compromised=false, last_rotated_at=now) so it passes §9's
 * launch gate. Idempotent on (site, provider).
 */
class WordpressConnector
{
    public function __construct(
        private readonly WordpressClientFactory $factory,
    ) {}

    /**
     * @param  array{base_url: string, username: string, app_password: string}  $input
     *
     * @throws WordpressException when the credential does not authenticate.
     */
    public function connect(string $siteId, array $input): Connection
    {
        $credentials = $this->normalize($input);
        $site = Site::query()->find($siteId);

        if (! $this->factory->usingCredentials($credentials, $site)->ping()) {
            throw new WordpressException(
                'Credentials did not authenticate against '.$credentials['base_url'].'/wp-json/wp/v2/users/me — nothing was saved.',
            );
        }

        return Connection::withoutGlobalScope(SiteScope::class)->updateOrCreate(
            ['site_id' => $siteId, 'provider' => ConnectionProvider::WpAppPassword->value],
            [
                'credentials' => $credentials,
                'status' => 'active',
                'compromised' => false,
                'compromised_reason' => null,
                'exposed_at' => null,
                'last_rotated_at' => now(),
            ],
        );
    }

    /**
     * @param  array{base_url: string, username: string, app_password: string}  $input
     * @return array{base_url: string, username: string, app_password: string}
     */
    private function normalize(array $input): array
    {
        return [
            'base_url' => rtrim(trim($input['base_url']), '/'),
            'username' => trim($input['username']),
            // WP shows application passwords space-grouped ("abcd efgh …"); the
            // spaces are cosmetic — strip them so Basic auth matches.
            'app_password' => (string) preg_replace('/\s+/', '', $input['app_password']),
        ];
    }
}
