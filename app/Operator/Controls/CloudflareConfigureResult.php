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
        public readonly string $status,   // configured | not_configured | invalid_token | no_zone | failed
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

    public static function invalidToken(): self
    {
        return new self(false, 'invalid_token', 'The Cloudflare API token was rejected — check it is active and has Zone → Zone (Read) + Zone → WAF (Edit).');
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
