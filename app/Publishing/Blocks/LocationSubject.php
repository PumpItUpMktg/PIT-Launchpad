<?php

namespace App\Publishing\Blocks;

use App\Integrations\Census\MunicipalityGazetteer;
use App\Models\Content;
use App\Models\CoverageArea;
use App\Models\Location;
use App\Models\Scopes\SiteScope;
use Illuminate\Support\Facades\Cache;

/**
 * The authoritative {city, state} subject of a location page — the town/city the page is actually about,
 * derived from STRUCTURED data, never from the drafter-authored (sometimes hallucinated) stored title.
 *
 * - A HUB page (location_id) grounds on its pinned GBP Location's own city/state (Location::cityState()).
 * - A TOWN page (parent_location_id) grounds on the census municipality it is anchored to: the CoverageArea
 *   whose geo_id equals the page's geo_id — a pure GEOID join (contents.geo_id == coverage_areas.geo_id),
 *   immune to a wrong title. Until a town page is anchored (geo_id null, or a geo_id in no coverage entry)
 *   there is no authoritative structured subject; the resolver reports anchored=false and returns the legacy
 *   title-parse, so render degrades to today's behaviour rather than guessing through the ambiguous name path.
 *
 * `anchored` is the trust flag: true only when the subject came from structured data (a hub's Location, or a
 * town's geo_id -> CoverageArea). Callers that must not launder a bad title (the deterministic title backfill,
 * the render-time title) act only on anchored pages.
 */
final class LocationSubject
{
    private const COUNTY_CACHE_DAYS = 30;

    public function __construct(private readonly MunicipalityGazetteer $gazetteer) {}

    /**
     * `city` is the bare town name (what name-keyed lookups — neighbours, local posts — match on); `label` is
     * the name to SHOW in the title and H1: the same as `city` unless another town in this tenant's coverage
     * carries the same name in the same state, in which case it is qualified with its county ("Newtown,
     * Bucks County") so two real towns never publish under one identical title — Google treats identical
     * titles at two URLs as duplicates and indexes only one. `county` is that qualifier, or null.
     *
     * @return array{city: string, state: string, anchored: bool, label: string, county: ?string}
     */
    public function resolve(Content $content): array
    {
        $isTown = $content->location_id === null && $content->parent_location_id !== null;
        $subject = $isTown ? $this->town($content) : $this->hub($content);

        return ['label' => $subject['city'], 'county' => null, ...$subject];
    }

    /**
     * A hub's subject is its pinned GBP Location's own city/state (structured Google address components,
     * falling back to the location name) — never title-derived.
     *
     * @return array{city: string, state: string, anchored: bool}
     */
    private function hub(Content $content): array
    {
        $location = Location::withoutGlobalScope(SiteScope::class)
            ->where('site_id', $content->site_id)
            ->find($content->location_id);

        if ($location === null) {
            return $this->titleParse($content);
        }

        ['city' => $city, 'state' => $state] = $location->cityState();
        if ($city === '') {
            $city = trim((string) $location->name);
        }

        return ['city' => $city, 'state' => strtoupper(trim($state)), 'anchored' => $city !== ''];
    }

    /**
     * A town's subject is the census municipality it is anchored to — the CoverageArea on the page's geo_id.
     * Un-anchored (no geo_id, or a geo_id in no coverage entry) → the legacy title-parse, anchored=false.
     *
     * @return array{city: string, state: string, anchored: bool}
     */
    private function town(Content $content): array
    {
        $geoId = $content->geo_id;
        if (is_string($geoId) && $geoId !== '') {
            $area = CoverageArea::withoutGlobalScope(SiteScope::class)
                ->where('site_id', $content->site_id)
                ->where('geo_id', $geoId)
                ->first(['name', 'state']);

            if ($area !== null && trim((string) $area->name) !== '') {
                $city = trim((string) $area->name);
                $state = strtoupper(trim((string) $area->state));
                $county = $this->collisionCounty((string) $content->site_id, $geoId, $city, $state);

                return [
                    'city' => $city,
                    'state' => $state,
                    'anchored' => true,
                    'label' => $county !== null ? $city.', '.$county : $city,
                    'county' => $county,
                ];
            }
        }

        return $this->titleParse($content);
    }

    /**
     * The county to qualify a town with, when — and only when — another coverage row in this tenant has the
     * same name in the same state under a different GEOID (two real towns, one name: Newtown in Bucks and
     * Newtown in Chester). A county subdivision's GEOID carries its county (STATE(2)+COUNTY(3)); a place
     * GEOID does not, and an unknown county is no qualifier at all rather than a guess. Null = no collision.
     */
    private function collisionCounty(string $siteId, string $geoId, string $city, string $state): ?string
    {
        $twin = CoverageArea::withoutGlobalScope(SiteScope::class)
            ->where('site_id', $siteId)
            ->where('geo_id', '!=', $geoId)
            ->whereRaw('lower(name) = ?', [mb_strtolower($city)])
            ->whereRaw('upper(coalesce(state, \'\')) = ?', [$state])
            ->exists();
        if (! $twin || strlen($geoId) !== 10) {
            return null;
        }

        $stateFips = substr($geoId, 0, 2);
        $names = Cache::remember(
            "lp.county_names.{$stateFips}",
            now()->addDays(self::COUNTY_CACHE_DAYS),
            function () use ($stateFips): array {
                $map = [];
                foreach ($this->gazetteer->countiesInState($stateFips) as $county) {
                    $map[$county->geoId] = $county->name;
                }

                return $map;
            },
        );
        $name = trim((string) ($names[substr($geoId, 0, 5)] ?? ''));
        if ($name === '') {
            return null;
        }

        return preg_match('/\bcounty$/i', $name) === 1 ? $name : $name.' County';
    }

    /**
     * The legacy town-from-title parse ("Pequannock, NJ" -> city/state). Always anchored=false — it is a
     * degrade for un-anchored pages, not an authoritative source, so title-laundering callers skip it.
     *
     * @return array{city: string, state: string, anchored: bool}
     */
    private function titleParse(Content $content): array
    {
        $title = trim((string) $content->title);
        if (preg_match('/^(.*?),\s*([A-Za-z]{2})\.?$/', $title, $m) === 1) {
            return ['city' => trim($m[1]), 'state' => strtoupper($m[2]), 'anchored' => false];
        }

        return ['city' => $title, 'state' => '', 'anchored' => false];
    }
}
