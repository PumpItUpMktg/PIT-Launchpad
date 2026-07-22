<?php

namespace App\KeywordGenerator\Derive;

use App\Models\Scopes\SiteScope;
use App\Models\Service;
use App\Models\Site;

/**
 * The symmetric twin of {@see DemandWithoutServiceReport}: a stated service the demand-derived
 * structure gave NO page. Keyword-first shapes the tree from measured demand, so a service the owner
 * offers but that carries little/no search volume (and is semantically off-axis — mold testing, a
 * dehumidifier, water-damage cleanup on a waterproofing site) matches no cluster above the floor. The
 * {@see ServiceStructureMapper} pins it to its nearest cluster but sets `structure_home_flagged` — it
 * won't earn its own page. This report surfaces those so nothing the owner sells drops silently; the
 * Silos step lets the operator file each into a topic as its own page or a mention.
 */
final class UncoveredServicesReport
{
    /**
     * @return list<array{id: string, name: string, description: string}>
     */
    public function for(Site $site): array
    {
        return Service::withoutGlobalScope(SiteScope::class)
            ->where('site_id', $site->id)
            ->where('structure_home_flagged', true)
            ->orderBy('name')
            ->get()
            ->map(fn (Service $service): array => [
                'id' => (string) $service->id,
                'name' => (string) $service->name,
                'description' => trim((string) $service->short_description),
            ])
            ->all();
    }
}
