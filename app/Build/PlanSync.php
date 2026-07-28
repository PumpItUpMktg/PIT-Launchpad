<?php

namespace App\Build;

use App\Enums\ContentKind;
use App\Locations\LocalRelevance;
use App\Models\Content;
use App\Models\Scopes\SiteScope;
use App\Models\Site;

/**
 * Reconcile the page plan with the CURRENT source records — the month-3 path: a client adds a
 * service line or a location mid-contract, the operator syncs from the pages board, and the new
 * pages appear in the work lane as "not generated" with a Generate action. Same idempotent
 * core the Launch step runs (seed town selection → assemble manifest → materialize), minus the
 * wizard-state flips — existing pages are never touched, only missing ones are added.
 */
class PlanSync
{
    public function __construct(
        private readonly LocalRelevance $relevance,
        private readonly BuildManifestAssembler $assembler,
        private readonly PageMaterializer $materializer,
        private readonly DuplicateTownSweeper $sweeper = new DuplicateTownSweeper,
    ) {}

    /** @return int the number of NEW planned pages added */
    public function sync(Site $site): int
    {
        $before = $this->pageCount($site);

        // New locations bring new served towns — seed their selection so location rows are real.
        $this->relevance->seedInitialSelection($site);
        $this->assembler->assemble($site);
        $this->materializer->materialize($site);

        // Self-heal: collapse any leftover duplicate town pages (the pre-dedupe bristol-pa-3/-4 shape)
        // so Sync plan cleans the backlog it never created — conservative (empty extras only, never a
        // live or in-review page). New duplicates can't form (the assembler dedupes by town), so this
        // only ever sweeps history.
        $this->sweeper->sweep($site);

        return max(0, $this->pageCount($site) - $before);
    }

    private function pageCount(Site $site): int
    {
        return Content::withoutGlobalScope(SiteScope::class)
            ->where('site_id', $site->id)
            ->where('kind', ContentKind::Page->value)
            ->count();
    }
}
