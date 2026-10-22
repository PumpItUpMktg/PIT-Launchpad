<?php

namespace App\Links;

use App\Enums\LinkProspectKind;
use App\Integrations\Census\MunicipalityGazetteer;
use App\Models\Location;
use App\Models\Scopes\SiteScope;
use App\Models\SiloBlueprint;
use App\Models\Site;

/**
 * The searches the local-opportunity discovery pass runs for a tenant (§ Links) — built ONLY from what the
 * tenant already told us: the trade (Business step), the offices (city / state / home county), and the
 * brands it installs or carries. No trade or brand is written into the templates, so the same pass serves a
 * sump pump company, a roofer, and an HVAC contractor. One query = {lane, text, office}; the list is
 * de-duplicated so two offices in one county share the county-level searches.
 */
final class LocalOpportunityQueries
{
    public function __construct(private readonly MunicipalityGazetteer $gazetteer) {}

    /**
     * @return list<array{lane: LinkProspectKind, query: string, location_id: string|null, office: string}>
     */
    public function for(Site $site): array
    {
        $lanes = $this->lanes($site);
        $trade = trim((string) (SiloBlueprint::withoutGlobalScope(SiteScope::class)->where('site_id', $site->id)->value('trade') ?? ''));
        $brands = array_values(array_filter(array_map(fn ($b): string => trim((string) $b), is_array($site->brands) ? $site->brands : []), fn (string $b): bool => $b !== ''));
        $offices = Location::withoutGlobalScope(SiteScope::class)->where('site_id', $site->id)->orderBy('created_at')->get();

        $out = [];
        $seen = [];
        $add = function (LinkProspectKind $lane, string $query, ?string $locationId, string $office) use (&$out, &$seen): void {
            $query = trim((string) preg_replace('/\s+/', ' ', $query));
            $key = strtolower($query);
            if ($query === '' || isset($seen[$key])) {
                return;
            }
            $seen[$key] = true;
            $out[] = ['lane' => $lane, 'query' => $query, 'location_id' => $locationId, 'office' => $office];
        };

        $states = [];
        foreach ($offices as $office) {
            ['city' => $city, 'state' => $state] = $office->cityState();
            $city = trim($city);
            $state = strtoupper(trim($state));
            if ($city === '' || $state === '') {
                continue;
            }
            $states[$state] = true;
            $county = $this->countyName($office, $state);
            $label = $city.', '.$state;

            if (in_array(LinkProspectKind::Sponsorship, $lanes, true)) {
                $add(LinkProspectKind::Sponsorship, "{$city} {$state} sponsorship opportunities", $office->id, $label);
                $add(LinkProspectKind::Sponsorship, "{$city} {$state} youth sports league sponsors", $office->id, $label);
                if ($county !== null) {
                    $add(LinkProspectKind::Sponsorship, "{$county} County {$state} community events sponsors", $office->id, $label);
                }
            }
            if (in_array(LinkProspectKind::Membership, $lanes, true)) {
                $add(LinkProspectKind::Membership, "{$city} {$state} chamber of commerce members", $office->id, $label);
                if ($county !== null) {
                    $add(LinkProspectKind::Membership, "{$county} County {$state} chamber of commerce", $office->id, $label);
                    $add(LinkProspectKind::Membership, "{$county} County {$state} home builders association members", $office->id, $label);
                }
            }
            if (in_array(LinkProspectKind::JobBoard, $lanes, true) && $county !== null) {
                $add(LinkProspectKind::JobBoard, "{$county} County {$state} workforce development employer partners", $office->id, $label);
                $add(LinkProspectKind::JobBoard, "{$county} County {$state} trade school employer partners", $office->id, $label);
            }
        }

        foreach (array_keys($states) as $state) {
            if ($trade !== '') {
                if (in_array(LinkProspectKind::Membership, $lanes, true)) {
                    $add(LinkProspectKind::Membership, "{$trade} contractors association {$state} members", null, $state);
                }
                if (in_array(LinkProspectKind::JobBoard, $lanes, true)) {
                    $add(LinkProspectKind::JobBoard, "{$trade} apprenticeship program {$state} employer partners", null, $state);
                }
            }
            if (in_array(LinkProspectKind::DealerLocator, $lanes, true)) {
                foreach ($brands as $brand) {
                    $add(LinkProspectKind::DealerLocator, "{$brand} find a dealer {$state}", null, $state);
                    $add(LinkProspectKind::DealerLocator, "{$brand} authorized installer locator {$state}", null, $state);
                }
            }
        }

        return $out;
    }

    /**
     * The lanes this tenant has switched on (all, when nothing is set).
     *
     * @return list<LinkProspectKind>
     */
    public function lanes(Site $site): array
    {
        $chosen = is_array($site->link_lanes) ? $site->link_lanes : null;
        if ($chosen === null || $chosen === []) {
            return LinkProspectKind::lanes();
        }
        $out = [];
        foreach (LinkProspectKind::lanes() as $lane) {
            if (in_array($lane->value, $chosen, true)) {
                $out[] = $lane;
            }
        }

        return $out !== [] ? $out : LinkProspectKind::lanes();
    }

    /** The office's home county name ("Montgomery"), from the gazetteer, or null when it is not known. */
    private function countyName(Location $office, string $state): ?string
    {
        $geoId = trim((string) $office->home_county_geoid);
        if ($geoId === '' || strlen($geoId) < 2) {
            return null;
        }
        try {
            foreach ($this->gazetteer->countiesInState(substr($geoId, 0, 2)) as $county) {
                if ($county->geoId === $geoId) {
                    return trim((string) preg_replace('/\s+County$/i', '', $county->name));
                }
            }
        } catch (\Throwable) {
            return null;
        }

        return null;
    }
}
