<?php

namespace Tests\Support;

use App\Integrations\Embedding\EmbeddingProvider;

/**
 * A controllable embedding fake for near-duplicate tests: texts containing the same TOPIC key land on
 * the same axis (cosine 1.0); anything else lands on its own axis (cosine 0.0). The hashed
 * MockEmbeddingProvider cannot express "these two titles mean the same thing" — real embeddings can.
 *
 * @param  array<string, string>  $topics  substring (lowercase) => topic key
 */
final class TopicEmbeddings implements EmbeddingProvider
{
    /** @param array<string, string> $topics */
    public function __construct(private readonly array $topics) {}

    /** @return list<float> */
    public function embed(string $text): array
    {
        $t = mb_strtolower($text);
        $keys = array_values(array_unique(array_values($this->topics)));
        $vector = array_fill(0, count($keys) + 1, 0.0);
        foreach ($this->topics as $needle => $key) {
            if (str_contains($t, $needle)) {
                $vector[(int) array_search($key, $keys, true)] = 1.0;

                return $vector;
            }
        }
        $vector[count($keys)] = 1.0; // "other" — orthogonal to every topic

        return $vector;
    }
}
