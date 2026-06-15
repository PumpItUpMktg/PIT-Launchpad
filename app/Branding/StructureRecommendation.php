<?php

namespace App\Branding;

/**
 * The AI's recommended structure preset (trust|bold|warm) for a brand, with a
 * one-line rationale. The slug is always one of the three valid presets — the model
 * proposes, BrandGenerator enforces the set (falling back to a deterministic
 * personality→structure map when the model returns something off-list).
 */
final class StructureRecommendation
{
    public function __construct(
        public readonly string $slug,
        public readonly string $rationale,
    ) {}

    /**
     * @return array{slug: string, rationale: string}
     */
    public function toArray(): array
    {
        return ['slug' => $this->slug, 'rationale' => $this->rationale];
    }
}
