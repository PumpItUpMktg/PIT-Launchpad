<?php

namespace App\Integrations\Embedding;

use Illuminate\Contracts\Cache\Repository as Cache;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Factory as Http;
use Illuminate\Http\Client\RequestException;
use Throwable;

/**
 * Live OpenAI embeddings adapter (text-embedding-3-small) behind the §6
 * EmbeddingProvider contract. Produces vectors only — near-duplicate/clustering
 * comparison stays in §6.
 *
 * Correctness invariant: every vector compared against another MUST share the
 * same model AND dimensionality. Both are pinned from config and folded into the
 * cache key, so a change to either is a clean re-embed (stale vectors can never
 * collide with new ones). Inputs are batched, re-associated to their request by
 * the response `index` (never by array position), truncated to the model's
 * per-input token cap, and cached by content hash so unchanged text is never
 * re-embedded.
 */
class OpenAiEmbeddingProvider implements EmbeddingProvider
{
    private const TRIES = 3;

    private const BACKOFF_MS = 500;

    /** OpenAI accepts up to 2048 inputs per embeddings request. */
    private const MAX_BATCH = 2048;

    /** Conservative chars-per-token estimate for the per-input cap (no tokenizer). */
    private const CHARS_PER_TOKEN = 4;

    public function __construct(
        private readonly Http $http,
        private readonly Cache $cache,
        private readonly string $apiKey,
        private readonly string $baseUrl,
        private readonly string $model,
        private readonly int $dimensions,
        private readonly int $maxInputTokens = 8191,
        private readonly int $cacheTtlHours = 720,
        private readonly int $timeout = 30,
    ) {}

    /**
     * @return list<float>
     */
    public function embed(string $text): array
    {
        return $this->embedMany([$text])[0];
    }

    /**
     * Batch-embed many texts in as few calls as possible. Returns vectors keyed
     * by the caller's original input index. Cached per (text × model × dimensions)
     * so unchanged text is reused rather than re-billed.
     *
     * @param  list<string>  $texts
     * @return array<int, list<float>>
     */
    public function embedMany(array $texts): array
    {
        /** @var array<int, list<float>> $out */
        $out = [];
        /** @var array<int, string> $pending  original index => truncated text */
        $pending = [];

        foreach ($texts as $i => $text) {
            $truncated = $this->truncate($text);
            $cached = $this->cache->get($this->cacheKey($truncated));
            if (is_array($cached)) {
                /** @var list<float> $cached */
                $out[$i] = $cached;
            } else {
                $pending[$i] = $truncated;
            }
        }

        foreach (array_chunk($pending, self::MAX_BATCH, preserve_keys: true) as $chunk) {
            $originalIndexes = array_keys($chunk);
            $vectors = $this->request(array_values($chunk));

            foreach ($vectors as $position => $vector) {
                $originalIndex = $originalIndexes[$position];
                $out[$originalIndex] = $vector;
                $this->cache->put($this->cacheKey($chunk[$originalIndex]), $vector, $this->cacheTtlHours * 3600);
            }
        }

        ksort($out);

        return $out;
    }

    /**
     * @param  list<string>  $inputs
     * @return list<list<float>> vectors in input order (re-mapped by response index)
     */
    private function request(array $inputs): array
    {
        if ($this->apiKey === '') {
            // Fail fast before any network call — never silently attempt with no key.
            throw new EmbeddingException('OPENAI_API_KEY not set', fatal: true);
        }

        $payload = [
            'model' => $this->model,
            'input' => $inputs,
            'encoding_format' => 'float',
        ];
        if ($this->dimensions > 0) {
            $payload['dimensions'] = $this->dimensions;
        }

        $response = $this->http
            ->withToken($this->apiKey)
            ->timeout($this->timeout)
            ->retry(self::TRIES, self::BACKOFF_MS, function (Throwable $e): bool {
                return $e instanceof ConnectionException
                    || ($e instanceof RequestException && in_array($e->response->status(), [429, 500, 502, 503], true));
            }, throw: false)
            ->post(rtrim($this->baseUrl, '/').'/embeddings', $payload);

        if (! $response->successful()) {
            $body = $response->json();
            $message = is_array($body) && isset($body['error']['message'])
                ? (string) $body['error']['message']
                : 'HTTP '.$response->status();

            throw new EmbeddingException(
                'OpenAI embeddings: '.$message,
                $response->status(),
                fatal: in_array($response->status(), [401, 403], true),
            );
        }

        $data = $response->json('data');
        if (! is_array($data)) {
            throw new EmbeddingException('OpenAI embeddings returned no data array.');
        }

        // Re-associate by the response `index`, never by array position.
        $byIndex = [];
        foreach ($data as $row) {
            if (! is_array($row) || ! isset($row['embedding']) || ! is_array($row['embedding'])) {
                continue;
            }
            $byIndex[(int) ($row['index'] ?? 0)] = array_map(fn ($v) => (float) $v, array_values($row['embedding']));
        }
        ksort($byIndex);

        return array_values($byIndex);
    }

    /**
     * Bound an input to the model's per-input context. Approximate (no tokenizer):
     * a conservative chars-per-token estimate keeps us safely under the cap.
     */
    private function truncate(string $text): string
    {
        $maxChars = $this->maxInputTokens * self::CHARS_PER_TOKEN;

        return mb_strlen($text) > $maxChars ? mb_substr($text, 0, $maxChars) : $text;
    }

    private function cacheKey(string $text): string
    {
        return "emb:{$this->model}:{$this->dimensions}:".sha1($text);
    }
}
