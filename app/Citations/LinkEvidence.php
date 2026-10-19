<?php

namespace App\Citations;

use App\Models\Content;
use App\Models\Directory;
use App\Models\Location;
use App\Models\Scopes\SiteScope;
use App\Models\SiteBacklink;
use Illuminate\Support\Collection;

/**
 * What the backlink index says about each directory, for one location (§ Citations × § Links): whether the
 * directory actually links to the site, from which listing page, and which page the link lands on — this
 * office's page, a sibling office's, the home page, another page, or one the index reports broken. It is
 * evidence beside the SERP scan's verdict, not a replacement for it: the scan reads the listing's NAP, the
 * index proves the listing exists and where it points, independent of what Google chose to show.
 *
 * One row per referring domain (its strongest link), so on a multi-office brand a directory's evidence
 * names whichever office that strongest link lands on.
 */
final class LinkEvidence
{
    public const THIS = 'this';

    public const SIBLING = 'sibling';

    public const HOME = 'home';

    public const OTHER = 'other';

    public const UNKNOWN = 'unknown';

    /**
     * @return array<string, array{state: string, label: string, listing_url: string, target_path: string, dofollow: bool, rank: int|null, last_seen: string|null, index_broken: bool}> keyed by directory id
     */
    public function forLocation(Location $location): array
    {
        $links = SiteBacklink::withoutGlobalScope(SiteScope::class)->where('site_id', $location->site_id)->get();
        if ($links->isEmpty()) {
            return [];
        }
        $directories = Directory::query()->where('is_active', true)->get(['id', 'domain']);
        $siblings = Location::withoutGlobalScope(SiteScope::class)->where('site_id', $location->site_id)->pluck('name', 'id')->map('strval');
        $owners = $this->owners($location->site_id, $links);

        $out = [];
        foreach ($directories as $dir) {
            $row = $links->first(fn (SiteBacklink $l): bool => self::onDomain($l->domain_from, (string) $dir->domain));
            if ($row === null) {
                continue;
            }
            [$state, $label] = $this->classify($row, (string) $location->id, $owners, $siblings);
            $out[(string) $dir->id] = [
                'state' => $state,
                'label' => $label,
                'listing_url' => (string) $row->url_from,
                'target_path' => (string) $row->target_path,
                'dofollow' => (bool) $row->dofollow,
                'rank' => $row->domain_from_rank,
                'last_seen' => $row->last_seen?->toDateString(),
                'index_broken' => (bool) $row->is_broken,
            ];
        }

        return $out;
    }

    /**
     * @param  Collection<int, SiteBacklink>  $links
     * @return array<string, array{location_id: string|null, found: bool}> target_path => the page's owning location
     */
    private function owners(string $siteId, Collection $links): array
    {
        $paths = $links->pluck('target_path')->unique()->reject(fn (string $p): bool => $p === '/')->values();
        $slugs = $paths->map(fn (string $p): string => ltrim($p, '/'))->all();
        $pages = $slugs === [] ? collect() : Content::withoutGlobalScope(SiteScope::class)
            ->where('site_id', $siteId)->whereIn('slug', $slugs)
            ->get(['slug', 'location_id', 'parent_location_id'])->keyBy('slug');

        $out = [];
        foreach ($paths as $path) {
            $page = $pages->get(ltrim($path, '/'));
            $out[$path] = [
                'location_id' => $page === null ? null : ($page->location_id ?? $page->parent_location_id),
                'found' => $page !== null,
            ];
        }

        return $out;
    }

    /**
     * @param  array<string, array{location_id: string|null, found: bool}>  $owners
     * @param  Collection<string, string>  $siblings
     * @return array{string, string}
     */
    private function classify(SiteBacklink $row, string $locationId, array $owners, Collection $siblings): array
    {
        $path = (string) $row->target_path;
        $broken = (bool) $row->is_broken;
        if ($path === '/') {
            return [self::HOME, 'Links to the home page'.($broken ? '' : '')];
        }
        $owner = $owners[$path] ?? ['location_id' => null, 'found' => false];
        if ($owner['location_id'] === $locationId) {
            return [self::THIS, 'Links to this office'];
        }
        if ($owner['location_id'] !== null) {
            return [self::SIBLING, 'Links to '.($siblings[$owner['location_id']] ?? 'another office')];
        }
        if ($owner['found']) {
            return [self::OTHER, 'Links to another page ('.$path.')'];
        }

        return [self::UNKNOWN, ($broken ? 'Links to a page the index reports broken' : 'Links to a page we do not serve').' ('.$path.')'];
    }

    public static function onDomain(string $domainFrom, string $directoryDomain): bool
    {
        $from = strtolower((string) preg_replace('/^www\./i', '', $domainFrom));
        $dir = strtolower((string) preg_replace('/^www\./i', '', $directoryDomain));

        return $dir !== '' && ($from === $dir || str_ends_with($from, '.'.$dir));
    }
}
