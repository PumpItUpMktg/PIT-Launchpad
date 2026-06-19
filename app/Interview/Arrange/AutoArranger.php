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
 * ships the structural passes that reuse the §6a embeddings:
 *
 *   - Pass B {@see CrossSiloDedup}: one keyword, one home (fold cross-silo near-dups in).
 *   - Pass C {@see SubClusterDetector}: flag a silo whose spokes cluster into another as a
 *     sub-hub demotion candidate (advisory only — applied by {@see SubHubDemoter} on accept).
 *   - Pass A {@see FoldTargetAssigner}: nest each folded spoke under its most-related core
 *     anywhere in its silo subtree.
 *   - Pass D {@see KeywordAssigner}: give each page a distinct primary keyword (cannibalization-safe).
 *
 * Order matters — C runs on the post-dedup set, A before D so keywords are assigned over
 * the final page set. A single shared {@see SpokeEmbeddings} memoizes every vector across
 * the passes. It only ever sets defaults on undecided spokes (Pass C mutates nothing);
 * operator-confirmed structure is preserved (the §10 twin), so a re-run never wipes a
 * decision. Pass E reconciliation + the writing command land in increment 4.
 */
final class AutoArranger
{
    public function __construct(
        private readonly EmbeddingProvider $embeddings,
        private readonly CrossSiloDedup $dedup,
        private readonly SubClusterDetector $subClusters,
        private readonly FoldTargetAssigner $nesting,
        private readonly KeywordAssigner $keywords,
    ) {}

    public function arrange(Site $site): ArrangeResult
    {
        $vectors = new SpokeEmbeddings($this->embeddings);
        $this->prewarmEmbeddings($site, $vectors);

        return $this->dedup->run($site, $vectors)
            ->merge($this->subClusters->run($site, $vectors))
            ->merge($this->nesting->run($site, $vectors))
            ->merge($this->keywords->run($site, $vectors));
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
