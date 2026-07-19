<?php

namespace App\Build;

use App\Enums\BlogTargetStatus;
use App\Models\BlogTarget;
use App\Models\Scopes\SiteScope;
use App\Models\SiloBlueprint;
use App\Models\Site;
use App\Models\Spoke;
use Illuminate\Support\Facades\DB;

/**
 * Clears a site's generated structure so it can be rebuilt from the CURRENT stated inputs — used after
 * the Service-catalog cleanup, when the spoke tree still mirrors the old (now-deleted) catalog. It
 * drops the derived layer only and keeps the seed:
 *
 *   - deletes every {@see Spoke} for the site (the tree the setup/silos view renders and materialize
 *     reads);
 *   - deletes the site's QUEUED {@see BlogTarget} rows (the directed lane keyed on those spokes'
 *     keywords) — consumed rows (drafted/published) are history and reference real content, so they
 *     stay;
 *   - resets the {@see SiloBlueprint}'s GENERATED state (`confirmed_at`, `prune_draft`,
 *     `client_approved_at/by`) so the Silos step reads "not generated yet", while KEEPING `trade` /
 *     `seed` / `transcript` — the interview inputs the regenerate reads.
 *
 * It intentionally does NOT touch: §1 Service rows (the clean stated list is the whole point), §4
 * Silo rows / keywords / published content (referenced downstream; a rebuild reconciles silos by name
 * and re-matches keywords by query). Regeneration is the operator's existing "generate structure"
 * action / keyword-first derive — this only clears.
 */
class StructureResetter
{
    /**
     * What a reset would remove for a site (no writes).
     *
     * @return array{spokes: int, queued_targets: int, blueprint: bool}
     */
    public function preview(Site $site): array
    {
        return [
            'spokes' => Spoke::withoutGlobalScope(SiteScope::class)->where('site_id', $site->id)->count(),
            'queued_targets' => BlogTarget::withoutGlobalScope(SiteScope::class)
                ->where('site_id', $site->id)
                ->where('status', BlogTargetStatus::Queued->value)
                ->count(),
            'blueprint' => SiloBlueprint::withoutGlobalScope(SiteScope::class)
                ->where('site_id', $site->id)
                ->whereNotNull('confirmed_at')
                ->exists(),
        ];
    }

    /**
     * Clear the generated structure for a site (spokes + queued targets + blueprint generated-state),
     * transactionally. Returns the same shape as {@see preview()} — the counts actually removed.
     *
     * @return array{spokes: int, queued_targets: int, blueprint: bool}
     */
    public function reset(Site $site): array
    {
        $counts = $this->preview($site);

        DB::transaction(function () use ($site): void {
            Spoke::withoutGlobalScope(SiteScope::class)->where('site_id', $site->id)->delete();

            BlogTarget::withoutGlobalScope(SiteScope::class)
                ->where('site_id', $site->id)
                ->where('status', BlogTargetStatus::Queued->value)
                ->delete();

            // Keep the seed (trade / seed / transcript); clear only what marks the tree "built".
            SiloBlueprint::withoutGlobalScope(SiteScope::class)
                ->where('site_id', $site->id)
                ->update([
                    'confirmed_at' => null,
                    'prune_draft' => null,
                    'client_approved_at' => null,
                    'client_approved_by' => null,
                ]);
        });

        return $counts;
    }
}
