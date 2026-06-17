<?php

namespace App\Locations\Dma;

/**
 * A covered metro/DMA (or a state-level fallback) to query DataForSEO against.
 * `locationName` is the DataForSEO location_name string; `name` is the display label.
 */
final class Metro
{
    public function __construct(
        public readonly string $name,
        public readonly string $locationName,
        public readonly bool $isFallback = false,
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return ['name' => $this->name, 'location_name' => $this->locationName, 'fallback' => $this->isFallback];
    }
}
