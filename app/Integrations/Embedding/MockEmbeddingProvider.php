<?php

namespace App\Integrations\Embedding;

/**
 * Deterministic bag-of-words embedding for tests and the default binding: tokens
 * are hashed into a fixed-width, L2-normalized vector, so cosine similarity
 * tracks lexical/semantic overlap closely enough to exercise near-dup.
 */
class MockEmbeddingProvider implements EmbeddingProvider
{
    private const DIMENSIONS = 64;

    /**
     * @return list<float>
     */
    public function embed(string $text): array
    {
        $vector = array_fill(0, self::DIMENSIONS, 0.0);

        foreach (preg_split('/[^a-z0-9]+/', mb_strtolower($text)) ?: [] as $token) {
            if (mb_strlen($token) < 3) {
                continue;
            }
            $vector[abs(crc32($token)) % self::DIMENSIONS] += 1.0;
        }

        $norm = sqrt(array_sum(array_map(fn (float $v) => $v * $v, $vector)));

        if ($norm > 0.0) {
            $vector = array_map(fn (float $v) => $v / $norm, $vector);
        }

        return $vector;
    }
}
