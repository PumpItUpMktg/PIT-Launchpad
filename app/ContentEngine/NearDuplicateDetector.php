<?php

namespace App\ContentEngine;

use App\Enums\NearDupTier;
use App\Integrations\Embedding\EmbeddingProvider;
use App\Integrations\Embedding\Vectors;
use App\Models\Content;
use Illuminate\Support\Collection;

/**
 * Candidate-stage near-duplicate prevention: semantic similarity (pluggable
 * embeddings, scoped to the matched silo for cost) plus keyword overlap for
 * directed cannibalization. Tiered: very-high vs a live page → refresh (don't
 * duplicate); moderate → operator flag; low → proceed.
 */
class NearDuplicateDetector
{
    public function __construct(
        private readonly EmbeddingProvider $embeddings,
        private readonly float $refreshThreshold = 0.9,
        private readonly float $flagThreshold = 0.7,
    ) {}

    /**
     * @param  Collection<int, Content>  $existing  existing content in the matched silo
     */
    public function detect(string $candidateText, Collection $existing): NearDupResult
    {
        if ($existing->isEmpty()) {
            return new NearDupResult(NearDupTier::Proceed, null, 0.0, 0.0);
        }

        $candidateVector = $this->embeddings->embed($candidateText);
        $candidateTokens = $this->tokens($candidateText);

        $bestSignal = 0.0;
        $bestSemantic = 0.0;
        $bestOverlap = 0.0;
        $bestId = null;

        foreach ($existing as $content) {
            $text = trim($content->title.' '.($content->body ?? '').' '.$content->slug);
            $semantic = Vectors::cosine($candidateVector, $this->embeddings->embed($text));
            $overlap = $this->jaccard($candidateTokens, $this->tokens($text));
            $signal = max($semantic, $overlap);

            if ($signal > $bestSignal) {
                $bestSignal = $signal;
                $bestSemantic = $semantic;
                $bestOverlap = $overlap;
                $bestId = $content->id;
            }
        }

        $tier = match (true) {
            $bestSignal >= $this->refreshThreshold => NearDupTier::Refresh,
            $bestSignal >= $this->flagThreshold => NearDupTier::OperatorFlag,
            default => NearDupTier::Proceed,
        };

        return new NearDupResult($tier, $bestId, $bestSemantic, $bestOverlap);
    }

    /**
     * @param  list<string>  $a
     * @param  list<string>  $b
     */
    private function jaccard(array $a, array $b): float
    {
        if ($a === [] || $b === []) {
            return 0.0;
        }

        $intersection = count(array_intersect($a, $b));
        $union = count(array_unique([...$a, ...$b]));

        return $intersection / $union;
    }

    /**
     * @return list<string>
     */
    private function tokens(string $text): array
    {
        $tokens = array_filter(
            preg_split('/[^a-z0-9]+/', mb_strtolower($text)) ?: [],
            fn (string $t) => mb_strlen($t) > 2,
        );

        return array_values(array_unique($tokens));
    }
}
