<?php

namespace App\Interview\Volume;

use App\Enums\SpokeGranularity;
use App\Locations\Dma\Metro;

/**
 * The volume grounding outcome: the per-spoke volumes, the metros actually queried,
 * and any metros skipped (their location_name didn't resolve against DataForSEO).
 */
final class VolumeResult
{
    /**
     * @param  list<SpokeVolume>  $spokes
     * @param  list<Metro>  $metros
     * @param  list<Metro>  $skippedMetros
     */
    public function __construct(
        public readonly array $spokes,
        public readonly array $metros,
        public readonly array $skippedMetros = [],
    ) {}

    public function foldedCount(): int
    {
        return count(array_filter($this->spokes, fn (SpokeVolume $s) => ! $s->isPillar && $s->granularity === SpokeGranularity::Folded));
    }

    public function ownPageCount(): int
    {
        return count(array_filter($this->spokes, fn (SpokeVolume $s) => ! $s->isPillar && $s->granularity === SpokeGranularity::OwnPage));
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'metros' => array_map(fn (Metro $m) => $m->toArray(), $this->metros),
            'skipped_metros' => array_map(fn (Metro $m) => $m->toArray(), $this->skippedMetros),
            'spokes' => array_map(fn (SpokeVolume $s) => $s->toArray(), $this->spokes),
        ];
    }
}
