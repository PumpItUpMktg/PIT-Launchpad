<?php

namespace App\Console\Commands\Concerns;

use App\Models\Location;
use App\Models\Scopes\SiteScope;
use App\Models\Site;

/**
 * Resolves a command's `--location` option (a brick-and-mortar shop id or name) to a Location id within
 * the given site — the operator's GEO area selection. Shared by the GEO seed commands.
 */
trait ResolvesSiteLocation
{
    /**
     * @return string|false|null the location id; null when `--location` wasn't given (all shops); false
     *                           when it was given but matches no shop (the caller should abort).
     */
    protected function resolveLocationId(Site $site): string|false|null
    {
        $arg = $this->option('location') !== null ? trim((string) $this->option('location')) : null;
        if ($arg === null || $arg === '') {
            return null;
        }

        $location = Location::withoutGlobalScope(SiteScope::class)
            ->where('site_id', $site->id)
            ->where(fn ($q) => $q->where('id', $arg)->orWhere('name', $arg))
            ->first();
        if ($location === null) {
            $this->error("No shop matches [{$arg}] for {$site->brand_name}.");

            return false;
        }

        return (string) $location->id;
    }
}
