<?php

namespace App\Publishing\Blocks;

use App\Models\Content;
use App\Models\CoverageArea;
use App\Models\Location;
use App\Models\Scopes\SiteScope;

/**
 * The authoritative {city, state} subject of a location page — the town/city the page is actually about,
 * derived from STRUCTURED data, never from the drafter-authored (sometimes hallucinated) stored title.
 *
 * - A HUB / landing page (location_id) grounds on its pinned GBP Location's own city/state
 *   (Location::cityState()). If that pin does not resolve (an un-pinned/orphan legacy landing, or a
 *   broken pin) it still grounds — anchored — on the page's OWN factory identity (its "{City}, {ST}"
 *   title, else its slug), never on the drafter-authored stored title.
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
    /**
     * @return array{city: string, state: string, anchored: bool}
     */
    public function resolve(Content $content): array
    {
        $isTown = $content->location_id === null && $content->parent_location_id !== null;

        return $isTown ? $this->town($content) : $this->hub($content);
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

        if ($location !== null) {
            ['city' => $city, 'state' => $state] = $location->cityState();
            if ($city === '') {
                $city = trim((string) $location->name);
            }
            if ($city !== '') {
                return ['city' => $city, 'state' => strtoupper(trim($state)), 'anchored' => true];
            }
        }

        // No resolvable pinned Location (an un-pinned/orphan landing, or a broken/soft-deleted pin). A
        // landing/hub page still has a structured identity of its OWN — the factory title "{City}, {ST}"
        // and the slug it was minted from ({@see \App\Locations\LocationLandingFactory}), both set from
        // the location's city/state at creation, never drafter prose. Resolve THOSE (anchored) rather
        // than degrading the title to the (sometimes hallucinated) stored meta.seo.title. Only
        // hub/landing/orphan pages reach here; town pages resolve via town(), so their conservative
        // un-anchored degrade is unchanged.
        return $this->landingIdentity($content);
    }

    /**
     * The landing/hub page's own structured place: its factory title "{City}, {ST}" first (proper casing),
     * else its slug's last segment ("…/hoboken-nj" → Hoboken, NJ). anchored=true when either yields a place;
     * the legacy title-parse degrade (anchored=false) only when neither does.
     *
     * @return array{city: string, state: string, anchored: bool}
     */
    private function landingIdentity(Content $content): array
    {
        $parsed = $this->titleParse($content);
        if ($parsed['state'] !== '') {
            return ['city' => $parsed['city'], 'state' => $parsed['state'], 'anchored' => true];
        }

        $slug = $this->placeFromSlug((string) $content->slug);
        if ($slug !== null) {
            return ['city' => $slug['city'], 'state' => $slug['state'], 'anchored' => true];
        }

        return $parsed;
    }

    /**
     * De-slug a location page's last path segment into a place: "markets/hoboken-nj" → Hoboken, NJ. A
     * trailing two-letter segment is the state. Returns null when there is no usable place segment.
     *
     * @return array{city: string, state: string}|null
     */
    private function placeFromSlug(string $slug): ?array
    {
        $parts = array_values(array_filter(explode('/', trim($slug, '/')), fn (string $s): bool => $s !== ''));
        if ($parts === []) {
            return null;
        }
        $segment = mb_strtolower((string) end($parts));

        $state = '';
        if (preg_match('/^(.+)-([a-z]{2})$/', $segment, $m) === 1) {
            $segment = $m[1];
            $state = strtoupper($m[2]);
        }

        $city = ucwords(str_replace('-', ' ', $segment));

        return $city !== '' ? ['city' => $city, 'state' => $state] : null;
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
                return [
                    'city' => trim((string) $area->name),
                    'state' => strtoupper(trim((string) $area->state)),
                    'anchored' => true,
                ];
            }
        }

        return $this->titleParse($content);
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
