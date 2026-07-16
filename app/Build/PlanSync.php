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
    ) {}

    /** @return int the number of NEW planned pages added */
    public function sync(Site $site): int
    {
        $before = $this->pageCount($site);

        // New locations bring new served towns — seed their selection so location rows are real.
        $this->relevance->seedInitialSelection($site);
        $this->assembler->assemble($site);
        $this->materializer->materialize($site);

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
