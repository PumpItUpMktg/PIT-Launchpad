<?php

namespace App\Integrations\Google;

use DateTimeImmutable;
use Illuminate\Http\Client\Factory as Http;

/**
 * Platform-level Google OAuth client: builds the consent URL and exchanges /
 * refreshes tokens against the token endpoint. Uses only the platform app creds
 * (client id/secret/redirect) — never a per-tenant token. Read-only scopes:
 * GSC (webmasters.readonly) + GA4 (analytics.readonly).
 */
class GoogleOAuthClient
{
    /** Read-only scopes for GSC search analytics + GA4 Data/Admin reads. */
    public const SCOPES = [
        'https://www.googleapis.com/auth/webmasters.readonly',
        'https://www.googleapis.com/auth/analytics.readonly',
    ];

    public function __construct(
        private readonly Http $http,
        private readonly string $clientId,
        private readonly string $clientSecret,
        private readonly string $redirectUri,
        private readonly string $authUri,
        private readonly string $tokenUri,
        private readonly int $timeout = 30,
    ) {}

    /**
     * Build the consent URL. `access_type=offline` + `prompt=consent` guarantee a
     * refresh token is issued; `state` carries CSRF + the site being connected.
     */
    public function authorizationUrl(string $state): string
    {
        $params = [
            'client_id' => $this->clientId,
            'redirect_uri' => $this->redirectUri,
            'response_type' => 'code',
            'scope' => implode(' ', self::SCOPES),
            'access_type' => 'offline',
            'prompt' => 'consent',
            'include_granted_scopes' => 'true',
            'state' => $state,
        ];

        return rtrim($this->authUri, '?').'?'.http_build_query($params);
    }

    public function exchangeCode(string $code): GoogleToken
    {
        return $this->token([
            'grant_type' => 'authorization_code',
            'code' => $code,
            'redirect_uri' => $this->redirectUri,
        ]);
    }

    public function refresh(string $refreshToken): GoogleToken
    {
        return $this->token([
            'grant_type' => 'refresh_token',
            'refresh_token' => $refreshToken,
        ], $refreshToken);
    }

    /**
     * @param  array<string, string>  $grant
     */
    private function token(array $grant, ?string $existingRefreshToken = null): GoogleToken
    {
        $response = $this->http
            ->asForm()
            ->timeout($this->timeout)
            ->post($this->tokenUri, $grant + [
                'client_id' => $this->clientId,
                'client_secret' => $this->clientSecret,
            ]);

        if (! $response->successful()) {
            $body = $response->json();
            $error = is_array($body) ? (string) ($body['error'] ?? 'token error') : 'token error';
            $description = is_array($body) ? (string) ($body['error_description'] ?? '') : '';

            // invalid_grant on a refresh = the refresh token is dead → reconnect.
            $needsReconnect = $grant['grant_type'] === 'refresh_token' && $error === 'invalid_grant';

            throw new GoogleException(
                "Google OAuth {$error}: {$description}",
                $response->status(),
                fatal: in_array($response->status(), [400, 401], true),
                needsReconnect: $needsReconnect,
            );
        }

        $json = (array) $response->json();
        $expiresIn = (int) ($json['expires_in'] ?? 3600);

        return new GoogleToken(
            accessToken: (string) ($json['access_token'] ?? ''),
            refreshToken: isset($json['refresh_token']) ? (string) $json['refresh_token'] : $existingRefreshToken,
            expiresAt: new DateTimeImmutable('@'.(time() + $expiresIn)),
            scopes: isset($json['scope']) ? explode(' ', (string) $json['scope']) : [],
        );
    }
}
