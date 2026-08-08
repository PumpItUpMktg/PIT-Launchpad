<?php

namespace App\Integrations\Cloudflare;

/**
 * The default binding when no Cloudflare token is configured (and the seam used in tests): it makes no
 * network call. It reports a healthy token, resolves a deterministic pseudo-zone for any domain, and
 * "creates" the rule — so the flow is exercisable end-to-end without Cloudflare. Tests that need a
 * specific outcome (no zone, failure) bind an anonymous client instead.
 */
final class MockCloudflareClient implements CloudflareClient
{
    public function verifyToken(): bool
    {
        return true;
    }

    public function zoneIdForDomain(string $domain): ?string
    {
        $domain = trim($domain);

        return $domain === '' ? null : 'zone_'.substr(md5($domain), 0, 12);
    }

    public function ensureLaunchpadSkipRule(string $zoneId): CloudflareRuleResult
    {
        return CloudflareRuleResult::created('rule_'.substr(md5($zoneId), 0, 12));
    }
}
