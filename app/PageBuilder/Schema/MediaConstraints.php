<?php

namespace App\PageBuilder\Schema;

/**
 * Constraints on a media slot: a declared minimum size, whether alt text is
 * required, and whether FLUX fabrication is permitted (hero_image on a location
 * page never fabricates a local scene).
 */
final class MediaConstraints
{
    public function __construct(
        public readonly ?int $minWidth = null,
        public readonly ?int $minHeight = null,
        public readonly bool $altRequired = true,
        public readonly bool $allowFabrication = true,
    ) {}

    /**
     * @param  array<string, mixed>  $data
     */
    public static function fromArray(array $data): self
    {
        return new self(
            minWidth: isset($data['min_width']) ? (int) $data['min_width'] : null,
            minHeight: isset($data['min_height']) ? (int) $data['min_height'] : null,
            altRequired: (bool) ($data['alt_required'] ?? true),
            allowFabrication: (bool) ($data['allow_fabrication'] ?? true),
        );
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'min_width' => $this->minWidth,
            'min_height' => $this->minHeight,
            'alt_required' => $this->altRequired,
            'allow_fabrication' => $this->allowFabrication,
        ];
    }
}
