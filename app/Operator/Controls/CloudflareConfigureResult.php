<?php

namespace App\Operator\Controls;

/**
 * The outcome of auto-configuring a site's Cloudflare edge for the control-plane sync — a status the
 * UI/command render verbatim.
 */
final class CloudflareConfigureResult
{
    private function __construct(
        public readonly bool $ok,
        public readonly string $status,   // configured | not_configured | invalid_token | unreachable | no_zone | failed
        public readonly string $message,
        public readonly ?string $zoneId = null,
        public readonly ?string $ruleId = null,
    ) {}

    public static function configured(string $zoneId, ?string $ruleId, string $message): self
    {
        return new self(true, 'configured', $message, $zoneId, $ruleId);
    }

    public static function notConfigured(): self
    {
        return new self(false, 'not_configured', 'Cloudflare isn’t connected — set CLOUDFLARE_API_TOKEN (a scoped token with Zone Read + WAF Edit) to enable auto-configuration.');
    }

    public static function invalidToken(?string $detail = null): self
    {
        $base = 'The Cloudflare API token was rejected (HTTP 401/403) — confirm the token value in the app env is exactly right (no quotes/whitespace, and config cache cleared), that it is active, and that it has Zone → Zone (Read) + Zone → WAF (Edit).';

        return new self(false, 'invalid_token', $detail !== null && $detail !== '' ? $base.' Cloudflare said: '.$detail : $base);
    }

    public static function unreachable(?string $detail = null): self
    {
        $base = 'Could not reach the Cloudflare API (api.cloudflare.com) — this is a network/egress problem, not the token. Check the app server can make outbound HTTPS to Cloudflare.';

        return new self(false, 'unreachable', $detail !== null && $detail !== '' ? $base.' ('.$detail.')' : $base);
    }

    public static function noZone(string $host): self
    {
        return new self(false, 'no_zone', "No Cloudflare zone found for {$host} on this account. If the site isn’t behind Cloudflare, no rule is needed; otherwise confirm the domain is in the connected Cloudflare account.");
    }

    public static function failed(string $message): self
    {
        return new self(false, 'failed', 'Cloudflare rejected the change: '.$message);
    }
}
