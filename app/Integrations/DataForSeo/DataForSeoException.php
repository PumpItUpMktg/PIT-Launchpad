<?php

namespace App\Integrations\DataForSeo;

use RuntimeException;

/**
 * A normalized DataForSEO failure. Covers transport errors and the vendor's
 * status_code envelope (non-20000), including auth/quota failures which are
 * surfaced loudly — never swallowed.
 */
class DataForSeoException extends RuntimeException
{
    public function __construct(string $message, public readonly ?int $statusCode = null, public readonly bool $fatal = false)
    {
        parent::__construct($message);
    }

    /** DataForSEO's per-minute rate-limit envelope code — transient, retried with backoff (NOT fatal). */
    public const RATE_LIMITED = 40202;

    public static function envelope(int $statusCode, string $message): self
    {
        // 401xx auth, 402xx payment/quota — fatal, surface loudly, do not retry. The one exception is
        // 40202 (rate limit per minute): transient, so it is retryable, not fatal.
        $fatal = $statusCode >= 40100 && $statusCode < 40300 && $statusCode !== self::RATE_LIMITED;

        return new self("DataForSEO status_code {$statusCode}: {$message}", $statusCode, $fatal);
    }
}
