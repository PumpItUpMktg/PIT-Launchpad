<?php

namespace App\Integrations\Embedding;

/**
 * Capability role: a text-embedding provider for semantic near-dup. Pluggable;
 * near-dup logic consumes this interface only.
 */
interface EmbeddingProvider
{
    /**
     * @return list<float>
     */
    public function embed(string $text): array;
}
