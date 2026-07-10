<?php

namespace App\Local\Proof;

/**
 * One nearby completed job (field job capture, not yet deployed) — filtered to the location by the
 * provider (radius from the location center, default ~20mi, or served-town membership).
 */
final class LocalJob
{
    /** @param list<string> $photos */
    public function __construct(
        public readonly string $title,
        public readonly string $description,
        public readonly array $photos,
        public readonly string $town,
        public readonly ?string $service = null,
        public readonly ?string $date = null,
    ) {}
}
