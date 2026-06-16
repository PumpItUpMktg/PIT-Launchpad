<?php

namespace App\Branding;

/**
 * The AI's pick from the curated library for a tenant + scheme: a single
 * CuratedPalette (always a real library entry — the model proposes an id, the
 * generator enforces the closed set, falling back to the scheme default) plus an
 * industry-grounded rationale shown beside the highlighted set in the picker.
 */
final class PaletteRecommendation
{
    public function __construct(
        public readonly CuratedPalette $palette,
        public readonly string $rationale,
    ) {}
}
