<?php

namespace App\Integrations\Cloudflare;

/**
 * The outcome of a Cloudflare token check — distinguishing the three failures that otherwise all read
 * as one opaque "rejected": the token was refused (401/403), the API was unreachable (network/egress),
 * or no token is set. `detail` carries Cloudflare's own error text when it gave one.
 */
final class CloudflareTokenStatus
{
    private function __construct(
        public readonly bool $ok,
        public readonly string $reason,   // active | rejected | unreachable | empty
        public readonly ?string $detail = null,
    ) {}

    public static function active(): self
    {
        return new self(true, 'active');
    }

    public static function rejected(?string $detail = null): self
    {
        return new self(false, 'rejected', $detail);
    }

    public static function unreachable(string $detail): self
    {
        return new self(false, 'unreachable', $detail);
    }

    public static function empty(): self
    {
        return new self(false, 'empty');
    }
}
