<?php

namespace App\Integrations\Vision;

use Illuminate\Http\Client\Factory as Http;

/**
 * The committed Claude vision adapter. Sends the rendered image URL to the
 * Anthropic messages API and returns concise, descriptive alt text. Hardened
 * with a request timeout; on any failure it falls back to the supplied context
 * so a vision hiccup never blocks a render.
 */
class ClaudeVisionClient implements VisionClient
{
    public function __construct(
        private readonly Http $http,
        private readonly string $key,
        private readonly string $model,
        private readonly int $timeout = 30,
    ) {}

    public function describe(string $imageUrl, ?string $context = null): string
    {
        $instruction = 'Write concise, descriptive alt text (under 125 characters) for this image'
            .($context !== null && $context !== '' ? ", in the context of: {$context}." : '.')
            .' Return only the alt text.';

        try {
            $response = $this->http
                ->withHeaders([
                    'x-api-key' => $this->key,
                    'anthropic-version' => '2023-06-01',
                ])
                ->timeout($this->timeout)
                ->acceptJson()
                ->post('https://api.anthropic.com/v1/messages', [
                    'model' => $this->model,
                    'max_tokens' => 200,
                    'messages' => [[
                        'role' => 'user',
                        'content' => [
                            ['type' => 'image', 'source' => ['type' => 'url', 'url' => $imageUrl]],
                            ['type' => 'text', 'text' => $instruction],
                        ],
                    ]],
                ]);

            $text = $response->json('content.0.text');

            if (is_string($text) && trim($text) !== '') {
                return trim($text);
            }
        } catch (\Throwable) {
            // Fall through to the context fallback below.
        }

        return $context !== null && $context !== '' ? $context : 'Image';
    }
}
