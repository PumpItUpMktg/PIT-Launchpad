<?php

namespace App\Build;

use App\Enums\SetupStep;
use App\Enums\SiteStatus;
use App\Guided\StepGate;
use App\Interview\Prune\PruneEngine;
use App\Jobs\SyncSiloCategories;
use App\Models\Site;

/**
 * The approve→build core, extracted from the guided Plan step's Approve so the new Setup's
 * step 8 (Launch) runs the identical proven sequence: implicit structure finalize (when the
 * blueprint isn't committed yet — empty decision-set = keep everything as arranged, the
 * stated-service floor), persist the build config, assemble the manifest, materialize the
 * planned page rows (no AI, no generation — pages build one at a time from the boards),
 * queue the WP category sync, and complete the wizard (approved/launched/build live, site
 * Onboarding → Active). Idempotent — a returning tenant can re-run to re-materialize.
 */
class ApproveAndBuild
{
    public function __construct(
        private readonly StepGate $stepGate,
        private readonly PruneEngine $pruneEngine,
        private readonly BuildManifestAssembler $assembler,
        private readonly PageMaterializer $materializer,
    ) {}

    public function approve(Site $site, bool $localize, int $townPagePace, bool $freshContent): void
    {
        $state = $this->stepGate->state($site);

        // Finalize the structure when it hasn't been committed yet (the old Structure step's
        // Finalize, now implicit in Approve). Idempotent — already-routed spokes are left untouched.
        if (! $state->structure_finalized) {
            $this->pruneEngine->finalize($site, []);
        }

        $state->update([
            'structure_finalized' => true,
            'inventory_reviewed' => true,
            'localize' => $localize,
            'town_page_pace' => max(1, $townPagePace),
            'fresh_content' => $freshContent,
        ]);

        // Cheap + instant: assemble the manifest, then materialize it into planned page rows.
        $this->assembler->assemble($site);
        $this->materializer->materialize($site);

        // Taxonomy is locked here — project the silo tree into WP categories now (queued, so
        // approve stays network-free) so they exist before the news engine publishes a post into
        // them. Idempotent; no-op until a WP connection is wired; go-live re-pushes as a backstop.
        SyncSiloCategories::enqueue($site);

        // The wizard-completion handoff fires HERE, on materialize-complete.
        $state->update([
            'approved' => true,
            'launched' => true,
            'build_status' => 'live',
            'current_step' => SetupStep::Grow->value,
        ]);
        if ($site->status === SiteStatus::Onboarding) {
            $site->update(['status' => SiteStatus::Active]);
        }
    }
}
