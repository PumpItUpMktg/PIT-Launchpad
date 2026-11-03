<?php

namespace App\Integrations\ColdOutreach;

/**
 * One Bird email-lookup answer. `result` is an open vocabulary (valid | neutral | risky |
 * undeliverable | typo, more may come); `confidence` (0–100) is always present and comparable.
 */
final readonly class EmailVerdict
{
    /**
     * @param  list<string>  $flags
     */
    public function __construct(
        public string $email,
        public string $result,
        public int $confidence,
        public array $flags = [],
        public ?string $reason = null,
        public ?string $didYouMean = null,
    ) {}

    /** @param  array<string, mixed>  $row */
    public static function fromArray(array $row, string $fallbackEmail): self
    {
        return new self(
            email: is_string($row['email'] ?? null) ? $row['email'] : $fallbackEmail,
            result: is_string($row['result'] ?? null) ? $row['result'] : 'unknown',
            confidence: max(0, min(100, (int) ($row['delivery_confidence'] ?? 0))),
            flags: array_values(array_filter((array) ($row['flags'] ?? []), 'is_string')),
            reason: is_string($row['reason'] ?? null) ? $row['reason'] : null,
            didYouMean: is_string($row['did_you_mean'] ?? null) ? $row['did_you_mean'] : null,
        );
    }
}
