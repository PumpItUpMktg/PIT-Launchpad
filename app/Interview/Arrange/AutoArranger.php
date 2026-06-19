<?php

namespace App\Interview\Arrange;

use App\Integrations\Embedding\EmbeddingProvider;
use App\Models\Scopes\SiteScope;
use App\Models\Site;
use App\Models\Spoke;

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
        $this->prewarmEmbeddings($site, $vectors);

        return $this->dedup->run($site, $vectors)
            ->merge($this->nesting->run($site, $vectors));
    }

    /**
     * Step 0 — generate-if-missing: embed every spoke name up front so clustering can
     * never run against missing/stale vectors. Memoized into the shared cache (and, on the
     * real provider, the persistent embedding cache), so the passes re-use them for free.
     */
    private function prewarmEmbeddings(Site $site, SpokeEmbeddings $vectors): void
    {
        Spoke::withoutGlobalScope(SiteScope::class)
            ->where('site_id', $site->id)
            ->get()
            ->each(fn (Spoke $spoke) => $vectors->vector($spoke));
    }
}
