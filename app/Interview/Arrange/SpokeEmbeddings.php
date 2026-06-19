<?php

namespace App\Interview\Arrange;

use App\Integrations\Embedding\EmbeddingProvider;
use App\Integrations\Embedding\Vectors;
use App\Models\Spoke;

/**
 * Memoized semantic vectors over spoke names for one auto-arrange run: every
 * relatedness decision (dedup, nesting, later sub-hub/keyword) rides on the §6a
 * EmbeddingProvider through this — never hand-rolled string matching. Each spoke
 * is embedded once (name + head keyword) and cached by id for the run's lifetime.
 */
final class SpokeEmbeddings
{
    /** @var array<string, list<float>> */
    private array $cache = [];

    public function __construct(private readonly EmbeddingProvider $embeddings) {}

    /**
     * @return list<float>
     */
    public function vector(Spoke $spoke): array
    {
        return $this->cache[$spoke->id] ??= $this->embeddings->embed($this->text($spoke));
    }

    /** Cosine similarity of two spokes' vectors (0..1 for non-negative embeddings). */
    public function similarity(Spoke $a, Spoke $b): float
    {
        return Vectors::cosine($this->vector($a), $this->vector($b));
    }

    /**
     * Embed an arbitrary text (e.g. a candidate primary keyword), memoized by the text so
     * Pass D's collision checks re-use vectors. Cached under a distinct key space from spokes.
     *
     * @return list<float>
     */
    public function embedText(string $text): array
    {
        return $this->cache['text:'.$text] ??= $this->embeddings->embed($text);
    }

    /** Cosine similarity of two arbitrary texts. */
    public function textSimilarity(string $a, string $b): float
    {
        return Vectors::cosine($this->embedText($a), $this->embedText($b));
    }

    private function text(Spoke $spoke): string
    {
        return trim($spoke->name.' '.(string) ($spoke->head_keyword ?? ''));
    }
}
