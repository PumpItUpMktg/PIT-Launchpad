<?php

namespace App\Integrations\Fal;

use Illuminate\Http\Client\Factory as Http;
use Throwable;

/**
 * The committed fal.ai adapter. Hardened from the pilot's scars: an explicit
 * HTTP timeout (the render call can never hang) and normalized errors — every
 * failure surfaces as a FalException so the render job can bound its retries
 * rather than propagate a raw provider error.
 */
class FalHttpClient implements FalClient
{
    public function __construct(
        private readonly Http $http,
        private readonly string $key,
        private readonly string $baseUrl,
        private readonly string $model,
        private readonly int $timeout,
    ) {}

    public function generate(string $prompt, array $options = []): FalImage
    {
        $width = (int) ($options['width'] ?? 1200);
        $height = (int) ($options['height'] ?? 675);

        try {
            $response = $this->http
                ->withToken($this->key, 'Key')
                ->timeout($this->timeout)
                ->acceptJson()
                ->post(rtrim($this->baseUrl, '/').'/'.ltrim($this->model, '/'), [
                    'prompt' => $prompt,
                    'image_size' => ['width' => $width, 'height' => $height],
                ]);
        } catch (Throwable $e) {
            throw new FalException('fal request failed: '.$e->getMessage(), previous: $e);
        }

        if (! $response->successful()) {
            throw new FalException('fal returned HTTP '.$response->status());
        }

        $url = $response->json('images.0.url');
        if (! is_string($url) || $url === '') {
            throw new FalException('fal response did not include an image URL.');
        }

        try {
            $image = $this->http->timeout($this->timeout)->get($url);
        } catch (Throwable $e) {
            throw new FalException('fal image download failed: '.$e->getMessage(), previous: $e);
        }

        if (! $image->successful()) {
            throw new FalException('fal image download returned HTTP '.$image->status());
        }

        return new FalImage(
            bytes: $image->body(),
            width: (int) ($response->json('images.0.width') ?? $width),
            height: (int) ($response->json('images.0.height') ?? $height),
            contentType: (string) ($image->header('Content-Type') ?: 'image/webp'),
        );
    }
}
