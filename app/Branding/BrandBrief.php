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
     * @param  list<string>  $adjectives  multi-select personality adjectives (Phase 4; enrich generation)
     */
    public function __construct(
        public readonly string $industry,
        public readonly string $personality,
        public readonly string $emotionalGoal = '',
        public readonly array $colorAnchorsUse = [],
        public readonly array $colorAnchorsAvoid = [],
        public readonly string $admiredBrand = '',
        public readonly array $adjectives = [],
    ) {}

    /** The curated personality options surfaced in the interview. */
    public const PERSONALITIES = [
        'trustworthy' => 'Trustworthy & established',
        'modern-technical' => 'Modern & technical',
        'friendly-local' => 'Friendly & local',
        'premium' => 'Premium & refined',
        'bold-urgent' => 'Bold & urgent',
    ];

    /** The richer adjective set (multi-select) that refines generation. */
    public const ADJECTIVES = [
        'trustworthy' => 'Trustworthy',
        'established' => 'Established',
        'premium' => 'Premium',
        'modern' => 'Modern',
        'bold' => 'Bold',
        'friendly' => 'Friendly',
        'local-family' => 'Local / family',
        'rugged' => 'Rugged',
        'clinical' => 'Clinical / precise',
        'approachable' => 'Approachable',
    ];

    /**
     * The personality descriptor the generation prompts express — the coarse
     * personality label, refined by any selected adjectives.
     */
    public function descriptor(): string
    {
        $base = self::PERSONALITIES[$this->personality] ?? $this->personality;

        if ($this->adjectives === []) {
            return $base;
        }

        $words = array_map(fn (string $a) => self::ADJECTIVES[$a] ?? $a, $this->adjectives);

        return $base.' — '.implode(', ', $words);
    }
}
