<?php

namespace App\Interview\Expansion;

use App\Enums\SpokeStatus;
use App\Enums\SpokeTag;
use App\Models\Scopes\SiteScope;
use App\Models\SiloBlueprint;
use App\Models\Site;
use App\Models\Spoke;
use Illuminate\Support\Facades\DB;

/**
 * Writes a Phase 2 candidate tree as a draft onto the site's SiloBlueprint: a pillar
 * spoke per silo plus its candidate spokes, and the fringe handoff set as fringe-tagged
 * spokes (which spawn no pages — the Routing layer reads them). All spokes land as
 * `candidate` status with null volume (Phase 3 fills volume, Phase 4 prunes). Re-running
 * replaces the prior candidate set in one transaction.
 */
final class ExpansionPersister
{
    public function persist(Site $site, ExpansionResult $result): SiloBlueprint
    {
        return DB::transaction(function () use ($site, $result): SiloBlueprint {
            $blueprint = SiloBlueprint::withoutGlobalScope(SiteScope::class)
                ->firstOrNew(['site_id' => $site->id]);
            $blueprint->save();

            // Re-expansion replaces the candidate set.
            Spoke::withoutGlobalScope(SiteScope::class)
                ->where('silo_blueprint_id', $blueprint->id)
                ->delete();

            foreach ($result->silos as $silo) {
                // The pillar page for the silo.
                $this->write($blueprint, $site, [
                    'silo' => $silo->name,
                    'is_pillar' => true,
                    'name' => $silo->name,
                    'page_type' => $silo->pageType,
                    'tag' => SpokeTag::Core,
                    'head_keyword' => $silo->headKeyword !== '' ? $silo->headKeyword : null,
                ]);

                foreach ($silo->spokes as $spoke) {
                    $this->write($blueprint, $site, [
                        'silo' => $silo->name,
                        'is_pillar' => false,
                        'name' => $spoke->name,
                        'page_type' => $spoke->pageType,
                        'tag' => $spoke->tag,
                        'head_keyword' => $spoke->headKeyword !== '' ? $spoke->headKeyword : null,
                        'connection_note' => $spoke->connectionNote,
                        'granularity' => $spoke->granularity,
                    ]);
                }
            }

            foreach ($result->fringeHandoff as $fringe) {
                $this->write($blueprint, $site, [
                    'silo' => 'Out of Lane',
                    'is_pillar' => false,
                    'name' => $fringe->name,
                    'tag' => SpokeTag::Fringe,
                    'connection_note' => $fringe->connectionNote,
                    'sibling_brand' => $fringe->siblingBrand,
                ]);
            }

            return $blueprint;
        });
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    private function write(SiloBlueprint $blueprint, Site $site, array $attributes): void
    {
        Spoke::create(array_merge([
            'silo_blueprint_id' => $blueprint->id,
            'site_id' => $site->id, // explicit: no current-site scope in console/job context
            'status' => SpokeStatus::Candidate,
            'volume' => null,
        ], $attributes));
    }
}
