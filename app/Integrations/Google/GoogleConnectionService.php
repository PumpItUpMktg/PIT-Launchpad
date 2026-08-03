<?php

namespace App\Integrations\Google;

use App\Enums\ConnectionStatus;
use App\Models\GoogleAccount;
use App\Models\Site;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Factory as Http;
use Illuminate\Http\Client\RequestException;
use Illuminate\Http\Client\Response;

/**
 * The shared platform Google grant + its token lifecycle. Owns the "one email" model: the operator
 * connects Google ONCE (a §9 platform secret, {@see GoogleAccount}), each client adds that email as a
 * user on their GSC + GA4 property, and every site queries through the single refreshing token.
 *
 * Token state (access/refresh/expiry, granted scopes, status) lives on the one {@see GoogleAccount};
 * WHICH property a given tenant reads is a non-secret pointer on the {@see Site} (gsc_property /
 * ga4_property), NOT on the shared grant. Lifecycle: connected → token valid → expired→refreshed
 * (persisted) → revoked→needs-reconnect. Authorized requests refresh on expiry and retry once on a
 * 401; a dead refresh token marks the grant needs-reconnect and surfaces loudly.
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

    /** The single shared grant, or null when Google has never been connected. */
    public function account(): ?GoogleAccount
    {
        return GoogleAccount::current();
    }

    /**
     * Persist a freshly granted token set onto the shared platform grant (create-or-update the
     * singleton). Tokens go in the encrypted credentials blob; granted scopes in the (non-secret)
     * scopes column; status → connected.
     */
    public function store(GoogleToken $token, ?string $label = null): GoogleAccount
    {
        $account = $this->account() ?? new GoogleAccount;

        $credentials = $account->credentials ?? [];
        $credentials['access_token'] = $token->accessToken;
        if ($token->refreshToken !== null && $token->refreshToken !== '') {
            $credentials['refresh_token'] = $token->refreshToken;
        }
        $credentials['expires_at'] = $token->expiresAt->format(DATE_ATOM);

        $account->credentials = $credentials;
        $account->scopes = $token->scopes !== [] ? $token->scopes : $account->scopes;
        if ($label !== null && $label !== '') {
            $account->label = $label;
        }
        $account->status = ConnectionStatus::Connected->value;
        $account->save();

        return $account;
    }

    /**
     * Record WHICH GSC site URL and/or GA4 property id this tenant reads — a non-secret pointer on
     * the Site, picked by the operator from the shared grant's visible set. Passing null clears the
     * pointer (the picker's "not connected" choice).
     */
    public function setSiteProperties(Site $site, ?string $gscProperty, ?string $ga4Property): Site
    {
        $site->gsc_property = $gscProperty !== '' ? $gscProperty : null;
        $site->ga4_property = $ga4Property !== '' ? $ga4Property : null;
        $site->save();

        return $site;
    }

    /**
     * A valid access token for the shared grant, refreshing + persisting if the stored one is expired
     * (or about to).
     */
    public function accessToken(GoogleAccount $account): string
    {
        $credentials = $account->credentials ?? [];
        $expiresAt = isset($credentials['expires_at']) ? strtotime((string) $credentials['expires_at']) : 0;

        if ($expiresAt - self::EXPIRY_SKEW <= time()) {
            return $this->refreshAccessToken($account);
        }

        return (string) ($credentials['access_token'] ?? '');
    }

    /**
     * Authorized JSON request against a Google API. Refreshes on expiry up front, retries once on a
     * 401 (token rejected mid-flight), and surfaces 403 (scope / API-not-enabled) and 429 (quota)
     * loudly.
     *
     * @param  array<string, mixed>  $options  ['query' => [...]] or ['json' => [...]]
     * @return array<string, mixed>
     */
    public function request(GoogleAccount $account, string $method, string $url, array $options = []): array
    {
        $response = $this->send($this->accessToken($account), $method, $url, $options);

        if ($response->status() === 401) {
            // Fresh-looking token still rejected — force one refresh and retry.
            $response = $this->send($this->refreshAccessToken($account), $method, $url, $options);
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
     * GSC properties available to the shared grant (for property selection).
     *
     * @return list<string>
     */
    public function listGscSites(GoogleAccount $account): array
    {
        $json = $this->request($account, 'get', config('services.google.gsc_base_url').'/sites');

        $sites = [];
        foreach ((array) ($json['siteEntry'] ?? []) as $entry) {
            if (is_array($entry) && isset($entry['siteUrl'])) {
                $sites[] = (string) $entry['siteUrl'];
            }
        }

        return $sites;
    }

    /**
     * GA4 properties available to the shared grant, via the Admin accountSummaries.
     *
     * @return list<array{property: string, displayName: string}>
     */
    public function listGa4Properties(GoogleAccount $account): array
    {
        $json = $this->request($account, 'get', config('services.google.ga4_admin_base_url').'/accountSummaries');

        $properties = [];
        foreach ((array) ($json['accountSummaries'] ?? []) as $summaryAccount) {
            foreach ((array) ($summaryAccount['propertySummaries'] ?? []) as $summary) {
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

    public function markNeedsReconnect(GoogleAccount $account, string $reason = ''): void
    {
        $account->status = ConnectionStatus::NeedsReconnect->value;
        $account->save();
    }

    private function refreshAccessToken(GoogleAccount $account): string
    {
        $credentials = $account->credentials ?? [];
        $refreshToken = (string) ($credentials['refresh_token'] ?? '');

        if ($refreshToken === '') {
            $this->markNeedsReconnect($account, 'no refresh token');
            throw new GoogleException('Google connection has no refresh token — reconnect required.', needsReconnect: true);
        }

        try {
            $token = $this->oauth->refresh($refreshToken);
        } catch (GoogleException $e) {
            if ($e->needsReconnect) {
                $this->markNeedsReconnect($account, $e->getMessage());
            }
            throw $e;
        }

        $credentials['access_token'] = $token->accessToken;
        $credentials['expires_at'] = $token->expiresAt->format(DATE_ATOM);
        if ($token->refreshToken !== null && $token->refreshToken !== '') {
            $credentials['refresh_token'] = $token->refreshToken;
        }
        $account->credentials = $credentials;
        if ($account->status !== ConnectionStatus::Connected->value) {
            $account->status = ConnectionStatus::Connected->value;
        }
        $account->save();

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

        return match (strtolower($method)) {
            'get' => $request->get($url, $options['query'] ?? []),
            'put' => $request->put($url, $options['json'] ?? []),
            'delete' => $request->delete($url, $options['json'] ?? []),
            'patch' => $request->patch($url, $options['json'] ?? []),
            default => $request->post($url, $options['json'] ?? []),
        };
    }
}
