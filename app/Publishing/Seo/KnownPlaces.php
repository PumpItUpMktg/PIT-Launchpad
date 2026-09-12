<?php

namespace App\Publishing\Seo;

use App\Models\CoverageArea;
use App\Models\Location;
use App\Models\Scopes\SiteScope;
use App\Models\Site;
use App\Support\TownName;
use Illuminate\Support\Str;

/**
 * Every place a site knows — coverage-area names, GBP location cities, and served towns — as the single
 * guard both wrong-town image rewrites share: a word (or slug token run) is treated as a town only when
 * the site actually knows it. "Service" before "PA" in a filename, or a Title-Case "Wall" in an image title,
 * is never a town on that basis alone.
 */
final class KnownPlaces
{
    /**
     * @return array{names: list<string>, slugs: array<string, true>} display names (original case, longest first)
     *                                                                 and their slugs
     */
    public function forSite(Site $site): array
    {
        $names = [];
        $slugs = [];
        $add = function (string $name) use (&$names, &$slugs): void {
            $display = TownName::display($name);
            $slug = Str::slug($display);
            if ($slug === '') {
                return;
            }
            $names[$display] = true;
            $slugs[$slug] = true;
        };

        foreach (CoverageArea::withoutGlobalScope(SiteScope::class)->where('site_id', $site->id)->pluck('name') as $name) {
            $add((string) $name);
        }
        foreach (Location::withoutGlobalScopes()->where('site_id', $site->id)->get() as $location) {
            $add($location->cityState()['city']);
            $servedTowns = $location->getAttribute('served_towns');
            foreach (is_array($servedTowns) ? $servedTowns : [] as $town) {
                if (is_array($town) && isset($town['name'])) {
                    $add((string) $town['name']);
                }
            }
        }

        $list = array_keys($names);
        usort($list, fn (string $a, string $b): int => mb_strlen($b) <=> mb_strlen($a));

        return ['names' => $list, 'slugs' => $slugs];
    }
}
