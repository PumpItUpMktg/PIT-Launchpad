<?php

namespace App\ContentEngine\Drafting;

use App\Models\Market;
use App\Models\Scopes\SiteScope;

/**
 * Decides whether — and with which towns — a draft may be localized. Town
 * injection is reserved for the reactive lane carrying local relevance; directed
 * and evergreen content stays town-agnostic so it does not read as spun
 * near-duplicate local pages. When allowed, towns come from the request's market
 * (falling back to the site's markets).
 */
class LocalInjectionPolicy
{
    /**
     * @return list<string>
     */
    public function townsFor(DraftRequest $request): array
    {
        if (! $request->allowsLocalInjection()) {
            return [];
        }

        $markets = Market::withoutGlobalScope(SiteScope::class)
            ->where('site_id', $request->siteId)
            ->when($request->marketId !== null, fn ($q) => $q->where('id', $request->marketId))
            ->get();

        $towns = [];
        foreach ($markets as $market) {
            $name = trim((string) $market->name);
            if ($name !== '') {
                $towns[] = $name;
            }
        }

        return array_values(array_unique($towns));
    }
}
