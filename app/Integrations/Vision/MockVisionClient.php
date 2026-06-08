<?php

namespace App\Integrations\Vision;

/**
 * Default vision adapter for tests/local: echoes the supplied context as the alt
 * text (or a generic fallback), so the render pipeline's alt-text step runs
 * without a network call.
 */
class MockVisionClient implements VisionClient
{
    public function describe(string $imageUrl, ?string $context = null): string
    {
        return $context !== null && $context !== '' ? $context : 'Image';
    }
}
