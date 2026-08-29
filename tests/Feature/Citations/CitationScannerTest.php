<?php

use App\Citations\CitationScanner;
use App\Enums\CitationPresence;
use App\Integrations\DataForSeo\DataForSeoClient;
use App\Models\CitationFoundDomain;
use App\Models\CitationStatus;
use App\Models\Directory;
use App\Models\Location;
use App\Models\LocationNapProfile;
use App\Models\Site;
use App\Support\CurrentSite;

/**
 * A DataForSEO double that returns a fixed organic result set regardless of query, so scan wiring is
 * deterministic without a network call.
 *
 * @param  list<array{position: int, url: string, domain: string}>  $organic
 */
function fakeDfs(array $organic): DataForSeoClient
{
    return new class($organic) extends DataForSeoClient
    {
        /** @param list<array{position: int, url: string, domain: string}> $organic */
        public function __construct(private array $organic)
        {
            // Intentionally skip parent constructor — no HTTP client is needed for the double.
        }

        public function liveOrganic(string $keyword, int $locationCode, string $language, int $depth): array
        {
            return $this->organic;
        }
    };
}

beforeEach(function (): void {
    $this->site = Site::factory()->create();
    CurrentSite::set($this->site->id);
});

test('a catalog match on a single-location tenant writes a status attributed to the sole location', function (): void {
    $location = Location::factory()->for($this->site)->create();
    LocationNapProfile::factory()->for($this->site)->create([
        'location_id' => $location->id,
        'business_name' => 'ACME Plumbing',
        'address_1' => '123 Main St',
        'city' => 'Clifton',
        'state' => 'NJ',
        'phone_primary' => '973-111-1111',
    ]);
    $yelp = Directory::factory()->create(['domain' => 'yelp.com', 'is_active' => true]);

    $scanner = new CitationScanner(fakeDfs([
        ['position' => 1, 'url' => 'https://www.yelp.com/biz/acme-plumbing', 'domain' => 'www.yelp.com'],
    ]));

    $written = $scanner->scanLocation($location);

    expect($written)->toBe(1);
    $status = CitationStatus::query()->where('location_id', $location->id)->where('directory_id', $yelp->id)->first();
    expect($status)->not->toBeNull()
        ->and($status->attributed_location_id)->toBe($location->id)
        ->and($status->presence)->toBe(CitationPresence::PresentMatch) // present + ours, no scraped NAP to fault it on
        ->and($status->attribution_confidence)->toBe(100);
});

test('an unmatched domain is persisted as a candidate with no status row', function (): void {
    $location = Location::factory()->for($this->site)->create();
    LocationNapProfile::factory()->for($this->site)->create(['location_id' => $location->id, 'business_name' => 'ACME']);

    $scanner = new CitationScanner(fakeDfs([
        ['position' => 3, 'url' => 'https://random-directory.io/acme', 'domain' => 'random-directory.io'],
    ]));

    $scanner->scanLocation($location);

    $domain = CitationFoundDomain::query()->where('location_id', $location->id)->where('domain', 'random-directory.io')->first();
    expect($domain)->not->toBeNull()
        ->and($domain->directory_id)->toBeNull()
        ->and(CitationStatus::query()->where('location_id', $location->id)->count())->toBe(0);
});

test('a matched domain is persisted with its directory id', function (): void {
    $location = Location::factory()->for($this->site)->create();
    LocationNapProfile::factory()->for($this->site)->create(['location_id' => $location->id, 'business_name' => 'ACME']);
    $bbb = Directory::factory()->create(['domain' => 'bbb.org', 'is_active' => true]);

    $scanner = new CitationScanner(fakeDfs([
        ['position' => 2, 'url' => 'https://www.bbb.org/us/nj/clifton/acme', 'domain' => 'www.bbb.org'],
    ]));
    $scanner->scanLocation($location);

    $domain = CitationFoundDomain::query()->where('location_id', $location->id)->where('domain', 'bbb.org')->first();
    expect($domain?->directory_id)->toBe($bbb->id);
});

test('re-scanning is idempotent — one status and one found-domain row per (location, directory)', function (): void {
    $location = Location::factory()->for($this->site)->create();
    LocationNapProfile::factory()->for($this->site)->create(['location_id' => $location->id, 'business_name' => 'ACME']);
    Directory::factory()->create(['domain' => 'yelp.com', 'is_active' => true]);

    $scanner = new CitationScanner(fakeDfs([
        ['position' => 1, 'url' => 'https://yelp.com/biz/acme', 'domain' => 'yelp.com'],
    ]));
    $scanner->scanLocation($location);
    $scanner->scanLocation($location);

    expect(CitationStatus::query()->where('location_id', $location->id)->count())->toBe(1)
        ->and(CitationFoundDomain::query()->where('location_id', $location->id)->where('domain', 'yelp.com')->count())->toBe(1);
});

test('a multi-location tenant with an organic-only result is parked for review', function (): void {
    $a = Location::factory()->for($this->site)->create();
    $b = Location::factory()->for($this->site)->create();
    LocationNapProfile::factory()->for($this->site)->create([
        'location_id' => $a->id, 'business_name' => 'ACME', 'address_1' => '123 Main St', 'city' => 'Clifton', 'phone_primary' => '973-111-1111',
    ]);
    LocationNapProfile::factory()->for($this->site)->create([
        'location_id' => $b->id, 'business_name' => 'ACME', 'address_1' => '999 Oak Ave', 'city' => 'Paramus', 'phone_primary' => '201-222-2222',
    ]);
    Directory::factory()->create(['domain' => 'yelp.com', 'is_active' => true]);

    $scanner = new CitationScanner(fakeDfs([
        ['position' => 1, 'url' => 'https://yelp.com/biz/acme', 'domain' => 'yelp.com'],
    ]));
    $scanner->scanLocation($a);

    $status = CitationStatus::query()->where('location_id', $a->id)->first();
    expect($status?->presence)->toBe(CitationPresence::Unknown)
        ->and($status?->needs_review)->toBeTrue();
});

test('a location with no NAP profile is skipped', function (): void {
    $location = Location::factory()->for($this->site)->create();
    Directory::factory()->create(['domain' => 'yelp.com', 'is_active' => true]);

    $scanner = new CitationScanner(fakeDfs([
        ['position' => 1, 'url' => 'https://yelp.com/biz/acme', 'domain' => 'yelp.com'],
    ]));

    expect($scanner->scanLocation($location))->toBe(0);
});
