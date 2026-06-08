<?php

namespace App\Integrations\Fal;

/**
 * A generated image returned by the fal adapter: the raw bytes plus dimensions
 * and content type, ready to upload to R2.
 */
final class FalImage
{
    public function __construct(
        public readonly string $bytes,
        public readonly int $width,
        public readonly int $height,
        public readonly string $contentType = 'image/webp',
    ) {}

    public function extension(): string
    {
        return match ($this->contentType) {
            'image/png' => 'png',
            'image/jpeg' => 'jpg',
            default => 'webp',
        };
    }
}
