<?php

namespace App\Integrations\Local;

/**
 * Normalized per-town local signals — the per-business inputs that decide a reserve town's
 * readiness to be built (the location-page drip). Every field is a 0–1 normalized index so the
 * relevance scorer is provider-agnostic: whichever real source lands later (Google Places
 * competitor density, Air Quality / Pollen for HVAC, GBP reviews, job-capture counts) maps into
 * this same shape. `population` is carried through as the real Census anchor.
 *
 * The key contract: these are resolved **per (site, town)**, so two businesses covering the same
 * town do NOT get identical data — the mock provider seeds off the site id, and real adapters key
 * on the site's own GBP/coverage.
 */
final class LocalSignals
{
    public function __construct(
        public readonly string $geoId,
        public readonly ?int $population,
        /** Competitor saturation in-town, 0 (open field) → 1 (saturated). A penalty on readiness. */
        public readonly float $competitorDensity,
        /** The business's local proof footprint (reviews / mentions) for this town, 0 → 1. */
        public readonly float $marketReviewIndex,
        /** Local search demand for the trade in this town, 0 → 1. */
        public readonly float $demandIndex,
    ) {}
}
