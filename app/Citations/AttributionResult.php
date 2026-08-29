<?php

namespace App\Citations;

/**
 * The outcome of attributing a found listing to one of a tenant's locations (§ Citations, Fix 1).
 * `ambiguous` means low confidence or a near-tie between siblings — the caller must NOT guess or emit a VA
 * task; a human resolves it once and the answer is stored.
 */
final readonly class AttributionResult
{
    public function __construct(
        public ?string $locationId,
        public int $confidence,     // 0-100
        public bool $ambiguous,
    ) {}

    public static function unresolved(): self
    {
        return new self(null, 0, true);
    }
}
