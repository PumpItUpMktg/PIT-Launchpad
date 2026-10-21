<?php

use App\Integrations\Backlinks\BacklinkPage;
use App\Integrations\Backlinks\BacklinkProvider;
use App\Integrations\Backlinks\BacklinkSummary;
use App\Integrations\Places\PlaceDetails;
use App\Integrations\Places\PlaceProfile;
use App\Integrations\Places\PlacesProvider;
use App\Integrations\Places\PlacesStatus;
use App\Models\CoverageArea;
use App\Models\GeoGridPoint;
use App\Models\GeoGridScan;
use App\Models\Keyword;
use App\Models\Location;
use App\Models\Site;

/** A Places provider whose profiles are scripted by place id. */
function scriptedPlaces(array $profiles): PlacesProvider
{
    return new class($profiles) implements PlacesProvider
    {
        /** @param  array<string, PlaceProfile>  $profiles */
        public function __construct(public array $profiles, public array $asked = []) {}

        public function search(string $query): array
        {
            return [];
        }

        public function details(string $placeId): ?PlaceDetails
        {
            return null;
        }

        public function profile(string $placeId): ?PlaceProfile
        {
            $this->asked[] = $placeId;

            return $this->profiles[$placeId] ?? null;
        }

        public function smokeTest(): PlacesStatus
        {
            return new PlacesStatus(true, 'ok');
        }
    };
}

function roadmapProfile(string $placeId, string $name, float $rating, int $ratings, string $type, int $photos, float $lat, float $lng, ?string $website = null, array $reviews = []): PlaceProfile
{
    return new PlaceProfile($placeId, $name, $rating, $ratings, [$type, 'point_of_interest', 'establishment'], $photos, 'OPERATIONAL', $lat, $lng, $website, null, $reviews);
}

function summaryBacklinks(array $byDomain): BacklinkProvider
{
    return new class($byDomain) implements BacklinkProvider
    {
        public function __construct(private array $byDomain) {}

        public function summary(string $target): BacklinkSummary
        {
            [$refs, $rank] = $this->byDomain[$target] ?? [0, null];

            return new BacklinkSummary(0, $refs, 0, $rank);
        }

        public function referringDomains(string $target, int $limit = 1000, ?string $after = null): BacklinkPage
        {
            return new BacklinkPage([], 0, null);
        }

        public function domainIntersection(array $targets, array $exclude, int $limit = 300): array
        {
            return [];
        }
    };
}

/**
 * One office with three towns, one priority keyword, and a coverage scan whose pack winners are Oriole
 * (every town), D&L (two towns) and a chain (one town); our rank 2 in Hackettstown, 5 in Washington, absent in Blairstown.
 *
 * @return array{site: Site, office: Location, keyword: Keyword, towns: array<string, CoverageArea>}
 */
function roadmapWorld(): array
{
    $site = Site::factory()->create(['domain_url' => 'https://sumppumpgurus.com']);
    $office = Location::factory()->create(['site_id' => $site->id, 'name' => 'Hackettstown', 'gbp_url' => 'https://maps.google.com/?cid=1', 'place_id' => 'PID_US', 'lat' => 40.8540, 'lng' => -74.8290]);
    $towns = [];
    foreach ([['3401', 'Hackettstown', 40.8540, -74.8290, 9700], ['3402', 'Washington', 40.7587, -74.9793, 6400], ['3403', 'Blairstown', 40.9790, -74.9610, 5900]] as [$geo, $name, $lat, $lng, $pop]) {
        $towns[$name] = CoverageArea::create(['site_id' => $site->id, 'geo_id' => $geo, 'name' => $name, 'type' => 'place', 'state' => 'NJ', 'lat' => $lat, 'lng' => $lng, 'source_location_ids' => [$office->id], 'population' => $pop, 'page_selected' => true]);
    }
    $keyword = Keyword::factory()->create(['site_id' => $site->id, 'query' => 'sump pump installation', 'town_priority_rank' => 1]);
    $scan = GeoGridScan::create(['site_id' => $site->id, 'location_id' => $office->id, 'keyword_id' => $keyword->id, 'provider' => 'dataforseo', 'mode' => 'coverage', 'grid_size' => 3, 'spacing_miles' => 0, 'center_lat' => 40.85, 'center_lng' => -74.83, 'zoom' => 13, 'depth_cap' => 20, 'status' => 'complete', 'scanned_at' => '2026-10-20 06:00:00']);
    $oriole = ['name' => 'Oriole Basement Waterproofing', 'place_id' => 'PID_ORIOLE', 'rank' => 1, 'domain' => 'www.oriolebw.com'];
    $dl = ['name' => 'D&L Pump and Well', 'place_id' => 'PID_DL', 'rank' => 2, 'domain' => 'dlpumpandwell.com'];
    $chain = ['name' => 'Tractor Supply', 'place_id' => 'PID_TSC', 'rank' => 3, 'domain' => 'tractorsupply.com'];
    foreach ([['Hackettstown', 2, [$oriole, $dl]], ['Washington', 5, [$oriole, $dl, $chain]], ['Blairstown', null, [$oriole]]] as $i => [$name, $rank, $competitors]) {
        GeoGridPoint::create(['site_id' => $site->id, 'scan_id' => $scan->id, 'row' => 0, 'col' => $i, 'coverage_area_id' => $towns[$name]->id, 'geo_id' => $towns[$name]->geo_id, 'label' => $name,
            'lat' => $towns[$name]->lat, 'lng' => $towns[$name]->lng, 'rank' => $rank, 'competitors' => $competitors, 'collected_at' => '2026-10-20 06:10:00']);
    }

    return ['site' => $site, 'office' => $office, 'keyword' => $keyword, 'towns' => $towns];
}
