<?php

namespace App\Branding;

/**
 * The short brand-interview answers the AI generator works from. Industry is
 * reused from what we already know (the tenant's services/silos) — never re-asked.
 * Personality is one of a curated set; the rest are low-friction and optional.
 */
class BrandBrief
{
    /**
     * @param  list<string>  $colorAnchorsUse  colors the client wants used (optional)
     * @param  list<string>  $colorAnchorsAvoid  colors to avoid (optional)
     */
    public function __construct(
        public readonly string $industry,
        public readonly string $personality,
        public readonly string $emotionalGoal = '',
        public readonly array $colorAnchorsUse = [],
        public readonly array $colorAnchorsAvoid = [],
        public readonly string $admiredBrand = '',
    ) {}

    /** The curated personality options surfaced in the interview. */
    public const PERSONALITIES = [
        'trustworthy' => 'Trustworthy & established',
        'modern-technical' => 'Modern & technical',
        'friendly-local' => 'Friendly & local',
        'premium' => 'Premium & refined',
        'bold-urgent' => 'Bold & urgent',
    ];
}
