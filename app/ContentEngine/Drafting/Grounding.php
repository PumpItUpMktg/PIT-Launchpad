<?php

namespace App\ContentEngine\Drafting;

use App\PageBuilder\Schema\KitSchema;

/**
 * The assembled, split grounding handed to the drafter. The two pools are kept
 * structurally separate — the Claims pool is the only source of business
 * assertions, the Source pool is context/attribution only — so accuracy is
 * enforced by construction rather than by prompt wording alone.
 */
final class Grounding
{
    /**
     * @param  list<Claim>  $claims  the only pool a business assertion may come from
     * @param  list<SourceRef>  $sources  context/attribution only — never a claim
     * @param  array<string, mixed>  $voiceProfile  versioned VoiceProfile JSON
     * @param  list<string>  $towns  local towns, populated only when injection is allowed
     */
    public function __construct(
        public readonly array $claims,
        public readonly array $sources,
        public readonly array $voiceProfile,
        public readonly int $voiceProfileVersion,
        public readonly bool $localInjectionAllowed,
        public readonly array $towns = [],
        public readonly ?KitSchema $kit = null,
    ) {}

    public function claim(string $id): ?Claim
    {
        foreach ($this->claims as $claim) {
            if ($claim->id === $id) {
                return $claim;
            }
        }

        return null;
    }

    public function source(string $name): ?SourceRef
    {
        foreach ($this->sources as $source) {
            if (strcasecmp($source->name, $name) === 0) {
                return $source;
            }
        }

        return null;
    }
}
