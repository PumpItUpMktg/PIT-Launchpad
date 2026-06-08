<?php

namespace App\Integrations\Wordpress;

use App\Enums\ConnectionProvider;
use App\Models\Connection;
use App\Models\Scopes\SiteScope;
use App\Models\Site;
use Illuminate\Http\Client\Factory as Http;

/**
 * Builds a WordpressClient for a site's WP application-password Connection,
 * decrypting the credentials from §9's vault at the last moment. The base URL,
 * username, and app password live in the (encrypted) credentials; the base URL
 * falls back to the site's domain. Credentials are never logged.
 */
class WordpressClientFactory
{
    public function __construct(
        private readonly Http $http,
    ) {}

    public function forSite(Site $site): WordpressClient
    {
        $connection = Connection::withoutGlobalScope(SiteScope::class)
            ->where('site_id', $site->id)
            ->where('provider', ConnectionProvider::WpAppPassword->value)
            ->first();

        if ($connection === null) {
            throw new WordpressException('No WordPress connection configured for this site.');
        }

        return $this->forConnection($connection, $site);
    }

    public function forConnection(Connection $connection, ?Site $site = null): WordpressClient
    {
        return $this->build($connection->credentials ?? [], $site);
    }

    /**
     * Build a client from explicit (e.g. candidate) credentials, for §9's
     * verify-before-revoke against live WordPress.
     *
     * @param  array<string, mixed>  $credentials
     */
    public function usingCredentials(array $credentials, ?Site $site = null): WordpressClient
    {
        return $this->build($credentials, $site);
    }

    /**
     * @param  array<string, mixed>  $credentials
     */
    private function build(array $credentials, ?Site $site): WordpressClient
    {
        $baseUrl = (string) ($credentials['base_url'] ?? $this->siteUrl($site));
        $username = (string) ($credentials['username'] ?? '');
        $appPassword = (string) ($credentials['app_password'] ?? $credentials['password'] ?? '');

        return new WordpressClient($this->http, $baseUrl, $username, $appPassword);
    }

    private function siteUrl(?Site $site): string
    {
        return $site !== null ? (string) $site->domain_url : '';
    }
}
