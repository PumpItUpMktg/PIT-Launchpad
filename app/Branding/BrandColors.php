<?php

namespace App\Branding;

/**
 * The usable brand colors pulled from a logo: a `primary` (always present when this object exists) and
 * an optional `accent`. A monochrome logo yields `primary` with a null `accent` — the accent is then
 * BORROWED from the nearest curated variation, never invented. When a logo yields no usable color at
 * all, the extractor returns null (no object) and the "Your brand colors" option simply doesn't appear.
 */
final class BrandColors
{
    public function __construct(
        public readonly string $primary,
        public readonly ?string $accent = null,
    ) {}

    public function isMonochrome(): bool
    {
        return $this->accent === null;
    }

    /** @return array{primary: string, accent: string|null} */
    public function toArray(): array
    {
        return ['primary' => $this->primary, 'accent' => $this->accent];
    }
}
