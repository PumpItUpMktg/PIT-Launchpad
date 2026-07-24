<?php

namespace App\Build;

use App\Enums\ServicePageTreatment;
use App\Enums\SpokeGranularity;
use App\Enums\SpokePageType;
use App\Enums\SpokeStatus;
use App\Enums\SpokeTag;
use App\Models\Scopes\SiteScope;
use App\Models\Service;
use App\Models\SiloBlueprint;
use App\Models\Site;
use App\Models\Spoke;
use Illuminate\Support\Facades\DB;

/**
 * Deterministic structure writer: turns the operator's AUTHORED service tree (top-level services with
 * grouped sub-services, each Page or Section) into the site's SiloBlueprint + Spoke set — the same
 * shape the AI expansion produces, but declared by the operator instead of guessed. What they group is
 * exactly what gets built, so there is no orphan "thin hub".
 *
 * Mapping (per top-level service S, in group order):
 *   - A **pillar** spoke named S heads a silo named S. Its Content renders as a HUB iff the silo has ≥1
 *     own_page child (the {@see PageMaterializer} decouple); with no page-children it renders as a plain
 *     SERVICE page. So S is a hub exactly when it has a Page child — never a spoke-less shell.
 *   - Each **Page** child → an own_page service spoke in S's silo (its own URL under the hub).
 *   - Each **Section** child → a Folded spoke (no page); it renders as a section on S's page (folds into
 *     the pillar — `fold_into_id` null).
 * A service with no children, or only Section children, is therefore a single rich service page.
 *
 * Idempotent: re-running replaces the blueprint's spoke set in one transaction. Author-declared spokes
 * land `Offered` (owner-confirmed routing — no AI prune), so the build manifest picks them up directly.
 */
final class ServiceStructureWriter
{
    public function write(Site $site): SiloBlueprint
    {
        return DB::transaction(function () use ($site): SiloBlueprint {
            $blueprint = SiloBlueprint::withoutGlobalScope(SiteScope::class)
                ->firstOrNew(['site_id' => $site->id]);
            $blueprint->save();

            Spoke::withoutGlobalScope(SiteScope::class)
                ->where('silo_blueprint_id', $blueprint->id)
                ->delete();

            $topLevel = Service::withoutGlobalScope(SiteScope::class)
                ->where('site_id', $site->id)
                ->whereNull('parent_service_id')
                ->with(['childServices' => fn ($q) => $q->withoutGlobalScope(SiteScope::class)])
                ->orderBy('group_order')
                ->orderBy('name')
                ->get();

            foreach ($topLevel as $service) {
                $silo = (string) $service->name;

                // The pillar page for the silo (the service's own page; hub-or-service is decided at
                // materialize by whether the silo has a page-child).
                $this->write_spoke($blueprint, $site, $silo, [
                    'is_pillar' => true,
                    'name' => $service->name,
                    'granularity' => SpokeGranularity::OwnPage,
                ]);

                foreach ($service->childServices as $child) {
                    $isPage = $child->page_treatment === ServicePageTreatment::Page;
                    $this->write_spoke($blueprint, $site, $silo, [
                        'is_pillar' => false,
                        'name' => $child->name,
                        // Page → its own URL; Section → folds into the parent page (fold_into_id null = pillar).
                        'granularity' => $isPage ? SpokeGranularity::OwnPage : SpokeGranularity::Folded,
                    ]);
                }
            }

            return $blueprint;
        });
    }

    /**
     * @param  array{is_pillar: bool, name: string, granularity: SpokeGranularity}  $attributes
     */
    private function write_spoke(SiloBlueprint $blueprint, Site $site, string $silo, array $attributes): void
    {
        Spoke::create([
            'silo_blueprint_id' => $blueprint->id,
            'site_id' => $site->id, // explicit: no current-site scope in console/job context
            'silo' => $silo,
            'page_type' => SpokePageType::Service,
            'tag' => SpokeTag::Core,
            'status' => SpokeStatus::Offered, // author-declared = owner-confirmed; no AI prune
            'volume' => null,
            ...$attributes,
        ]);
    }
}
