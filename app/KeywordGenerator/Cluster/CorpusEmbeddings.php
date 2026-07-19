<?php

namespace App\KeywordGenerator\Cluster;

use App\Integrations\Embedding\EmbeddingProvider;
use App\Integrations\Embedding\OpenAiEmbeddingProvider;
use App\Models\KeywordCorpus;

/**
 * Embeds corpus terms for clustering, memoized per canonical so a term is embedded once per run. Uses
 * the provider's batch path (one/few HTTP calls) when it's the OpenAI adapter, else falls back to the
 * single-embed interface (the deterministic test mock). The OpenAI adapter also caches each vector by
 * content hash, so re-runs of an unchanged corpus are free.
 */
final class CorpusEmbeddings
{
    /** @var array<string, list<float>> canonical => vector */
    private array $cache = [];

    public function __construct(private readonly EmbeddingProvider $embeddings) {}

    /**
     * @param  list<KeywordCorpus>  $terms
     * @return array<string, list<float>> canonical => vector
     */
    public function vectors(array $terms): array
    {
        $need = [];
        foreach ($terms as $term) {
            if (! isset($this->cache[$term->canonical])) {
                $need[$term->canonical] = $term->term;
            }
        }

        if ($need !== []) {
            if ($this->embeddings instanceof OpenAiEmbeddingProvider) {
                $canonicals = array_keys($need);
                $vectors = $this->embeddings->embedMany(array_values($need));
                foreach ($canonicals as $i => $canonical) {
                    $this->cache[$canonical] = $vectors[$i] ?? $this->embeddings->embed($need[$canonical]);
                }
            } else {
                foreach ($need as $canonical => $text) {
                    $this->cache[$canonical] = $this->embeddings->embed($text);
                }
            }
        }

        $out = [];
        foreach ($terms as $term) {
            $out[$term->canonical] = $this->cache[$term->canonical];
        }

        return $out;
    }
}
