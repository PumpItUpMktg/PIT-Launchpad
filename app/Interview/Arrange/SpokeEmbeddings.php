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

    private function text(Spoke $spoke): string
    {
        return trim($spoke->name.' '.(string) ($spoke->head_keyword ?? ''));
    }
}
