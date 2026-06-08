<?php

namespace App\SiloCreator;

use App\Models\Scopes\SiteScope;
use App\Models\Silo;
use App\Models\SiloLink;

/**
 * The internal-linking model. Within a silo, pillar <-> clusters (both ways)
 * and siblings link — derivable from the tree. Across silos, links are
 * controlled and persisted as silo_links rows. Emission is the SEO layer's job.
 */
class InternalLinking
{
    public function register(Silo $from, Silo $to, string $relation = 'cross_silo'): SiloLink
    {
        return SiloLink::firstOrCreate(
            ['from_silo_id' => $from->id, 'to_silo_id' => $to->id],
            ['site_id' => $from->site_id, 'relation' => $relation],
        );
    }

    /**
     * The link model for a silo: its pillar, sub-silos (clusters), siblings, and
     * controlled cross-silo links.
     *
     * @return array{pillar_content_id: string|null, children: list<string>, siblings: list<string>, cross_silo: list<string>}
     */
    public function modelFor(Silo $silo): array
    {
        $children = Silo::withoutGlobalScope(SiteScope::class)
            ->where('parent_silo_id', $silo->id)
            ->pluck('id')
            ->all();

        $siblings = $silo->parent_silo_id === null
            ? []
            : Silo::withoutGlobalScope(SiteScope::class)
                ->where('parent_silo_id', $silo->parent_silo_id)
                ->whereKeyNot($silo->id)
                ->pluck('id')
                ->all();

        $crossSilo = SiloLink::withoutGlobalScope(SiteScope::class)
            ->where('from_silo_id', $silo->id)
            ->pluck('to_silo_id')
            ->all();

        return [
            'pillar_content_id' => $silo->pillar_content_id,
            'children' => array_map('strval', $children),
            'siblings' => array_map('strval', $siblings),
            'cross_silo' => array_map('strval', $crossSilo),
        ];
    }
}
