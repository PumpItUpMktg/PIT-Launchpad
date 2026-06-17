<?php

namespace App\Interview\Volume;

use App\Enums\SpokeGranularity;

/**
 * One spoke's grounded volume: the aggregate (summed across covered metros), the
 * per-metro breakdown, and the resulting advisory granularity (own-page vs folded).
 */
final class SpokeVolume
{
    /**
     * @param  array<string, int>  $breakdown  metro display name → volume
     */
    public function __construct(
        public readonly string $silo,
        public readonly string $name,
        public readonly ?string $headKeyword,
        public readonly int $volume,
        public readonly array $breakdown,
        public readonly SpokeGranularity $granularity,
        public readonly bool $isPillar,
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'silo' => $this->silo,
            'name' => $this->name,
            'head_keyword' => $this->headKeyword,
            'volume' => $this->volume,
            'breakdown' => $this->breakdown,
            'granularity' => $this->granularity->value,
            'is_pillar' => $this->isPillar,
        ];
    }
}
