<?php

namespace App\Integrations\Cloudflare;

/**
 * The thin, swappable seam to the Cloudflare API (agency-wide token). Mock-first: {@see MockCloudflareClient}
 * is the default container binding so nothing here touches the network in tests; {@see HttpCloudflareClient}
 * binds when a token is configured. Scope is deliberately tiny — everything needed to unblock a tenant's
 * WordPress at the edge and nothing else.
 */
interface CloudflareClient
{
    /** Whether the configured token authenticates and is active. */
    public function verifyToken(): bool;

    /** The zone id owning $domain (apex resolved from a host/subdomain), or null when no zone matches. */
    public function zoneIdForDomain(string $domain): ?string;

    /**
     * Create or refresh the single Launchpad-managed WAF custom rule that SKIPs remaining custom rules,
     * the managed WAF, and security products for the `/wp-json/launchpad/*` path only — so the control
     * plane's authed sync is never blocked (403) or stripped (401) at the edge. Idempotent by the rule's
     * description marker: a second call updates the same rule rather than stacking duplicates.
     */
    public function ensureLaunchpadSkipRule(string $zoneId): CloudflareRuleResult;
}
