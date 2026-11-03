<?php

namespace App\Integrations\ColdOutreach;

use App\Enums\ConnectionProvider;
use App\Models\Connection;
use App\Models\Scopes\SiteScope;
use App\Models\Site;

/**
 * Resolves the Bird / Instantly / Brevo API key for a site: the site's own `Connection` first (so a
 * tenant can run on its own accounts), else the platform key from config/services.php (the agency
 * account every tenant shares by default). Keys are decrypted here at the last moment and never logged.
 */
class OutreachCredentials
{
    public function apiKey(Site|string $site, ConnectionProvider $provider): string
    {
        $key = $this->siteCredentials($site, $provider)['api_key'] ?? null;
        if (! is_string($key) || $key === '') {
            $key = (string) config('services.'.$provider->value.'.api_key', '');
        }

        if ($key === '') {
            throw new ColdOutreachException(ucfirst($provider->value).' API key is not configured for this site or the platform.', fatal: true);
        }

        return $key;
    }

    /** The shared secret Instantly sends back on webhooks for this site, if one was generated. */
    public function webhookSecret(Site|string $site): ?string
    {
        $secret = $this->siteCredentials($site, ConnectionProvider::Instantly)['webhook_secret'] ?? null;

        return is_string($secret) && $secret !== '' ? $secret : null;
    }

    public function hasSiteConnection(Site|string $site, ConnectionProvider $provider): bool
    {
        return $this->connection($site, $provider) !== null;
    }

    public function isConfigured(Site|string $site, ConnectionProvider $provider): bool
    {
        $key = $this->siteCredentials($site, $provider)['api_key'] ?? null;

        return (is_string($key) && $key !== '') || (string) config('services.'.$provider->value.'.api_key', '') !== '';
    }

    /**
     * Store (or merge into) the site's credentials for a provider. A freshly entered key is stored
     * clean (compromised=false, last_rotated_at=now) like the WordPress connector, so it passes the
     * §9 launch gate.
     *
     * @param  array<string, string>  $credentials
     */
    public function store(Site|string $site, ConnectionProvider $provider, array $credentials): Connection
    {
        $siteId = $site instanceof Site ? $site->id : $site;
        $existing = $this->connection($siteId, $provider);
        $merged = array_merge($existing->credentials ?? [], array_filter($credentials, fn ($v) => $v !== ''));

        return Connection::withoutGlobalScope(SiteScope::class)->updateOrCreate(
            ['site_id' => $siteId, 'provider' => $provider->value],
            [
                'credentials' => $merged,
                'status' => 'active',
                'compromised' => false,
                'compromised_reason' => null,
                'exposed_at' => null,
                'last_rotated_at' => now(),
            ],
        );
    }

    /** @return array<string, mixed> */
    private function siteCredentials(Site|string $site, ConnectionProvider $provider): array
    {
        return $this->connection($site, $provider)->credentials ?? [];
    }

    private function connection(Site|string $site, ConnectionProvider $provider): ?Connection
    {
        return Connection::withoutGlobalScope(SiteScope::class)
            ->where('site_id', $site instanceof Site ? $site->id : $site)
            ->where('provider', $provider->value)
            ->first();
    }
}
