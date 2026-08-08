<?php

namespace App\Operator\Controls;

use App\Integrations\Cloudflare\CloudflareClient;

/**
 * Auto-configures a tenant's Cloudflare edge so the control-plane WordPress sync isn't blocked (403) or
 * stripped (401): resolve the zone for the site's domain, then upsert the single `/wp-json/launchpad/*`
 * WAF skip rule. Pure orchestration over the {@see CloudflareClient} seam (mock in tests) — returns a
 * structured result the connect UI / command render verbatim. It never stores anything; the token is a
 * platform secret in config, and the change lives in Cloudflare.
 */
final class CloudflareConfigurator
{
    public function __construct(private readonly CloudflareClient $cloudflare) {}

    public function configureForUrl(string $baseUrl): CloudflareConfigureResult
    {
        if ((string) config('services.cloudflare.api_token', '') === '') {
            return CloudflareConfigureResult::notConfigured();
        }

        $host = $this->host($baseUrl);
        if ($host === '') {
            return CloudflareConfigureResult::noZone('(no domain)');
        }

        $token = $this->cloudflare->verifyToken();
        if (! $token->ok) {
            return $token->reason === 'unreachable'
                ? CloudflareConfigureResult::unreachable($token->detail)
                : CloudflareConfigureResult::invalidToken($token->detail);
        }

        $zoneId = $this->cloudflare->zoneIdForDomain($host);
        if ($zoneId === null) {
            return CloudflareConfigureResult::noZone($host);
        }

        $rule = $this->cloudflare->ensureLaunchpadSkipRule($zoneId);
        if (! $rule->ok) {
            return CloudflareConfigureResult::failed((string) $rule->message);
        }

        $verb = $rule->action === 'updated' ? 'updated' : 'created';

        return CloudflareConfigureResult::configured(
            $zoneId,
            $rule->ruleId,
            "Cloudflare edge configured for {$host} — WAF skip rule {$verb} for /wp-json/launchpad/*. Retry the WordPress connection.",
        );
    }

    private function host(string $baseUrl): string
    {
        $value = trim($baseUrl);
        if ($value === '') {
            return '';
        }
        if (! str_contains($value, '://')) {
            $value = 'https://'.$value;
        }

        return strtolower((string) (parse_url($value, PHP_URL_HOST) ?? ''));
    }
}
