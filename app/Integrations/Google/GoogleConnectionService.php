<?php

namespace App\Integrations\Google;

use App\Enums\ConnectionProvider;
use App\Enums\ConnectionStatus;
use App\Models\Connection;
use App\Models\Scopes\SiteScope;
use App\Models\Site;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Factory as Http;
use Illuminate\Http\Client\RequestException;
use Illuminate\Http\Client\Response;

/**
 * Per-tenant Google connection vault + lifecycle. Owns the §9 two-tier split:
 * platform OAuth creds stay in env (the GoogleOAuthClient), while each client's
 * access/refresh tokens, granted scopes, selected GSC/GA4 property IDs and
 * connection status live encrypted on the per-site `google` Connection.
 *
 * Lifecycle: connected → token valid → expired→refreshed (persisted) →
 * revoked→needs-reconnect. Authorized requests refresh on expiry and retry once
 * on a 401; a dead refresh token marks the connection needs-reconnect and is
 * surfaced loudly — never a swallowed failure or a crashed pipeline.
 */
class GoogleConnectionService
{
    /** Refresh this many seconds before the token actually expires. */
    private const EXPIRY_SKEW = 60;

    public function __construct(
        private readonly Http $http,
        private readonly GoogleOAuthClient $oauth,
        private readonly int $timeout = 30,
    ) {}

    public function connectionFor(Site $site): ?Connection
    {
        return Connection::withoutGlobalScope(SiteScope::class)
            ->where('site_id', $site->id)
            ->where('provider', ConnectionProvider::Google->value)
            ->first();
    }

    /**
     * Persist a freshly granted token set, creating or updating the site's Google
     * connection. Tokens go in the encrypted credentials blob; granted scopes in
     * the (non-secret) scopes column; status → connected.
     */
    public function store(Site $site, GoogleToken $token): Connection
    {
        $connection = $this->connectionFor($site) ?? new Connection([
            'site_id' => $site->id,
            'provider' => ConnectionProvider::Google,
        ]);

        $credentials = $connection->credentials ?? [];
        $credentials['access_token'] = $token->accessToken;
        if ($token->refreshToken !== null && $token->refreshToken !== '') {
            $credentials['refresh_token'] = $token->refreshToken;
        }
        $credentials['expires_at'] = $token->expiresAt->format(DATE_ATOM);

        $connection->credentials = $credentials;
        $connection->scopes = $token->scopes !== [] ? $token->scopes : $connection->scopes;
        $connection->status = ConnectionStatus::Connected->value;
        $connection->save();

        return $connection;
    }

    /**
     * Record the selected GSC site URL and/or GA4 property id for this connection.
     */
    public function selectProperties(Connection $connection, ?string $gscProperty, ?string $ga4Property): Connection
    {
        $credentials = $connection->credentials ?? [];
        if ($gscProperty !== null) {
            $credentials['gsc_property'] = $gscProperty;
        }
        if ($ga4Property !== null) {
            $credentials['ga4_property'] = $ga4Property;
        }
        $connection->credentials = $credentials;
        $connection->save();

        return $connection;
    }

    /**
     * A valid access token for the connection, refreshing + persisting if the
     * stored one is expired (or about to).
     */
    public function accessToken(Connection $connection): string
    {
        $credentials = $connection->credentials ?? [];
        $expiresAt = isset($credentials['expires_at']) ? strtotime((string) $credentials['expires_at']) : 0;

        if ($expiresAt - self::EXPIRY_SKEW <= time()) {
            return $this->refreshAccessToken($connection);
        }

        return (string) ($credentials['access_token'] ?? '');
    }

    /**
     * Authorized JSON request against a Google API. Refreshes on expiry up front,
     * retries once on a 401 (token rejected mid-flight), and surfaces 403 (scope /
     * API-not-enabled) and 429 (quota) loudly.
     *
     * @param  array<string, mixed>  $options  ['query' => [...]] or ['json' => [...]]
     * @return array<string, mixed>
     */
    public function request(Connection $connection, string $method, string $url, array $options = []): array
    {
        $response = $this->send($this->accessToken($connection), $method, $url, $options);

        if ($response->status() === 401) {
            // Fresh-looking token still rejected — force one refresh and retry.
            $response = $this->send($this->refreshAccessToken($connection), $method, $url, $options);
        }

        if (! $response->successful()) {
            $body = $response->json();
            $message = is_array($body) && isset($body['error']['message'])
                ? (string) $body['error']['message']
                : 'HTTP '.$response->status();

            throw new GoogleException(
                'Google API: '.$message,
                $response->status(),
                fatal: in_array($response->status(), [401, 403], true),
            );
        }

        return (array) $response->json();
    }

    /**
     * GSC properties available to this grant (for property selection).
     *
     * @return list<string>
     */
    public function listGscSites(Connection $connection): array
    {
        $json = $this->request($connection, 'get', config('services.google.gsc_base_url').'/sites');

        $sites = [];
        foreach ((array) ($json['siteEntry'] ?? []) as $entry) {
            if (is_array($entry) && isset($entry['siteUrl'])) {
                $sites[] = (string) $entry['siteUrl'];
            }
        }

        return $sites;
    }

    /**
     * GA4 properties available to this grant, via the Admin accountSummaries.
     *
     * @return list<array{property: string, displayName: string}>
     */
    public function listGa4Properties(Connection $connection): array
    {
        $json = $this->request($connection, 'get', config('services.google.ga4_admin_base_url').'/accountSummaries');

        $properties = [];
        foreach ((array) ($json['accountSummaries'] ?? []) as $account) {
            foreach ((array) ($account['propertySummaries'] ?? []) as $summary) {
                if (! is_array($summary) || ! isset($summary['property'])) {
                    continue;
                }
                $properties[] = [
                    'property' => (string) $summary['property'],
                    'displayName' => (string) ($summary['displayName'] ?? ''),
                ];
            }
        }

        return $properties;
    }

    public function markNeedsReconnect(Connection $connection, string $reason = ''): void
    {
        $connection->status = ConnectionStatus::NeedsReconnect->value;
        $connection->save();
    }

    private function refreshAccessToken(Connection $connection): string
    {
        $credentials = $connection->credentials ?? [];
        $refreshToken = (string) ($credentials['refresh_token'] ?? '');

        if ($refreshToken === '') {
            $this->markNeedsReconnect($connection, 'no refresh token');
            throw new GoogleException('Google connection has no refresh token — reconnect required.', needsReconnect: true);
        }

        try {
            $token = $this->oauth->refresh($refreshToken);
        } catch (GoogleException $e) {
            if ($e->needsReconnect) {
                $this->markNeedsReconnect($connection, $e->getMessage());
            }
            throw $e;
        }

        $credentials['access_token'] = $token->accessToken;
        $credentials['expires_at'] = $token->expiresAt->format(DATE_ATOM);
        if ($token->refreshToken !== null && $token->refreshToken !== '') {
            $credentials['refresh_token'] = $token->refreshToken;
        }
        $connection->credentials = $credentials;
        if ($connection->status !== ConnectionStatus::Connected->value) {
            $connection->status = ConnectionStatus::Connected->value;
        }
        $connection->save();

        return $token->accessToken;
    }

    /**
     * @param  array<string, mixed>  $options
     */
    private function send(string $accessToken, string $method, string $url, array $options): Response
    {
        $request = $this->http
            ->withToken($accessToken)
            ->timeout($this->timeout)
            ->retry(3, 400, fn ($e) => $e instanceof ConnectionException
                || ($e instanceof RequestException && in_array($e->response->status(), [429, 500, 502, 503], true)), throw: false);

        if (strtolower($method) === 'get') {
            return $request->get($url, $options['query'] ?? []);
        }

        return $request->post($url, $options['json'] ?? []);
    }
}
