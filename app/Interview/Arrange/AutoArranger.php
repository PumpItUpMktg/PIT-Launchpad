<?php

namespace App\Interview\Arrange;

use App\Integrations\Embedding\EmbeddingProvider;
use App\Models\Site;

/**
 * auto-arrange — turns the raw silo-volume output into the recommended, cannibalization-
 * safe, properly-nested structure, auto-resolving the mechanical decisions and flagging
 * the judgment calls for operator confirm. The full pipeline is B→C→A→D→E; this increment
 * ships the two structural passes that reuse the §6a embeddings:
 *
 *   - Pass B {@see CrossSiloDedup}: one keyword, one home (fold cross-silo near-dups in).
 *   - Pass A {@see FoldTargetAssigner}: nest each folded spoke under its most-related core.
 *
 * Order matters — A runs after B so it sees the final silo membership. A single shared
 * {@see SpokeEmbeddings} memoizes every vector across both passes (names don't change).
 * It only ever sets defaults on undecided spokes; operator-confirmed structure is
 * preserved (the §10 twin), so a re-run never wipes a decision.
 */
final class AutoArranger
{
    public function __construct(
        private readonly EmbeddingProvider $embeddings,
        private readonly CrossSiloDedup $dedup,
        private readonly FoldTargetAssigner $nesting,
    ) {}

    public function arrange(Site $site): ArrangeResult
    {
        $vectors = new SpokeEmbeddings($this->embeddings);

        return $this->dedup->run($site, $vectors)
            ->merge($this->nesting->run($site, $vectors));
    }
}
