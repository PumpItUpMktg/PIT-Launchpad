<?php

namespace App\Integrations\Cloudflare;

/**
 * Outcome of writing the Launchpad WAF skip rule: whether it landed, what happened (created / updated /
 * unchanged / failed), the rule id, and a human message on failure.
 */
final class CloudflareRuleResult
{
    private function __construct(
        public readonly bool $ok,
        public readonly string $action,
        public readonly ?string $ruleId = null,
        public readonly ?string $message = null,
    ) {}

    public static function created(?string $ruleId): self
    {
        return new self(true, 'created', $ruleId);
    }

    public static function updated(?string $ruleId): self
    {
        return new self(true, 'updated', $ruleId);
    }

    public static function failed(string $message): self
    {
        return new self(false, 'failed', null, $message);
    }
}
