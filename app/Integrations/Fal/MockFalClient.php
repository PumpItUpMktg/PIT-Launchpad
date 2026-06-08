<?php

namespace App\Integrations\Fal;

/**
 * Deterministic fal adapter for tests/local: returns a fixed tiny image so the
 * render pipeline is exercisable end-to-end without a network call.
 */
class MockFalClient implements FalClient
{
    // A 1x1 transparent WEBP, base64-encoded.
    private const PIXEL = 'UklGRhoAAABXRUJQVlA4TA0AAAAvAAAAEAcQERGIiP4HAA==';

    public function generate(string $prompt, array $options = []): FalImage
    {
        return new FalImage(
            bytes: (string) base64_decode(self::PIXEL),
            width: (int) ($options['width'] ?? 1200),
            height: (int) ($options['height'] ?? 675),
            contentType: 'image/webp',
        );
    }
}
