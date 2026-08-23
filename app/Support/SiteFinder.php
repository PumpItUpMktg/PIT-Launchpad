<?php

namespace App\Support;

use App\Models\Site;
use Illuminate\Support\Collection;

/**
 * Forgiving site lookup for console commands. Resolves a human-typed identifier
 * (site id, brand name, or domain — full or partial) to the matching sites, so a
 * command can accept "sump", "Sump Pump Gurus", the domain, or the ulid
 * interchangeably and report the real options when a guess misses. Operator/console
 * context, so it looks across all tenants (global scopes dropped).
 */
class SiteFinder
{
    /**
     * Matches for a typed identifier. An exact id wins outright (returns just that
     * site); otherwise a case-insensitive substring match on brand name or domain.
     *
     * @return Collection<int, Site>
     */
    public static function matches(string $needle): Collection
    {
        $needle = trim($needle);

        if ($needle === '') {
            return collect();
        }

        $exact = Site::withoutGlobalScopes()->whereKey($needle)->first();
        if ($exact !== null) {
            return collect([$exact]);
        }

        $like = '%'.mb_strtolower($needle).'%';

        return Site::withoutGlobalScopes()
            ->whereRaw('lower(brand_name) like ?', [$like])
            ->orWhereRaw("lower(coalesce(domain_url, '')) like ?", [$like])
            ->orderBy('brand_name')
            ->get();
    }

    /**
     * Every tenant, brand-name ordered — for a "did you mean" listing on a miss.
     *
     * @return Collection<int, Site>
     */
    public static function all(): Collection
    {
        return Site::withoutGlobalScopes()->orderBy('brand_name')->get();
    }
}
