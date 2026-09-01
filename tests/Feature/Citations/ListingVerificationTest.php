<?php

use App\Citations\CitationScanner;
use App\Enums\CitationPresence;
use App\Integrations\Citations\HttpListingVerifier;
use App\Integrations\Citations\ListingVerifier;
use App\Integrations\Citations\VerifiedListing;
use App\Integrations\DataForSeo\DataForSeoClient;
use App\Models\CitationStatus;
use App\Models\Directory;
use App\Models\Location;
use App\Models\LocationNapProfile;
use App\Models\Site;
use App\Support\CurrentSite;
use Illuminate\Support\Facades\Http;

/** @param list<array{position:int,url:string,domain:string}> $organic */
function verDfs(array $organic): DataForSeoClient
{
    return new class($organic) extends DataForSeoClient
    {
        /** @param list<array{position:int,url:string,domain:string}> $organic */
        public function __construct(private array $organic)
        {
            // no HTTP client needed for the double
        }

        public function liveOrganic(string $keyword, int $locationCode, string $language, int $depth): array
        {
            return $this->organic;
        }
    };
}

/** A verifier that returns a canned NAP per URL substring. */
function fakeVerifier(callable $resolve): ListingVerifier
{
    return new class($resolve) implements ListingVerifier
    {
        public function __construct(private $resolve) {}

        public function verify(string $directoryDomain, string $url): ?VerifiedListing
        {
            return ($this->resolve)($url);
        }
    };
}

// --- The HTTP verifier itself ---------------------------------------------------------------------------

test('the HTTP verifier reads NAP from schema.org JSON-LD', function (): void {
    Http::fake(['*' => Http::response('<html><head>'
        .'<script type="application/ld+json">'.json_encode([
            '@context' => 'https://schema.org', '@type' => 'LocalBusiness',
            'name' => 'Sump Pump Gurus', 'telephone' => '+1 484-808-2225',
            'address' => ['@type' => 'PostalAddress', 'streetAddress' => '123 Trooper Rd', 'addressLocality' => 'Trooper', 'addressRegion' => 'PA', 'postalCode' => '19403'],
        ]).'</script></head><body></body></html>', 200)]);

    $listing = (new HttpListingVerifier)->verify('yelp.com', 'https://www.yelp.com/biz/x');

    expect($listing)->not->toBeNull()
        ->and($listing->name)->toBe('Sump Pump Gurus')
        ->and($listing->phone)->toBe('+1 484-808-2225')
        ->and($listing->address)->toContain('123 Trooper Rd')
        ->and($listing->address)->toContain('19403');
});

test('the HTTP verifier falls back to a phone regex when there is no JSON-LD', function (): void {
    Http::fake(['*' => Http::response('<html><body>Call us at (484) 808-2225 today</body></html>', 200)]);

    $listing = (new HttpListingVerifier)->verify('bbb.org', 'https://bbb.org/x');

    expect($listing)->not->toBeNull()
        ->and($listing->phone)->toBe('(484) 808-2225');
});

test('the HTTP verifier returns null on a blocked/non-200 response', function (): void {
    Http::fake(['*' => Http::response('nope', 403)]);

    expect((new HttpListingVerifier)->verify('yelp.com', 'https://www.yelp.com/biz/x'))->toBeNull();
});

// --- Attribution with verification (the multi-location payoff) -------------------------------------------

function twoLocationTenant(): array
{
    $site = Site::factory()->create();
    CurrentSite::set($site->id);
    $trooper = Location::factory()->for($site)->create(['name' => 'Trooper']);
    LocationNapProfile::factory()->for($site)->create([
        'location_id' => $trooper->id, 'business_name' => 'Sump Pump Gurus',
        'address_1' => '123 Trooper Rd', 'city' => 'Trooper', 'state' => 'PA', 'phone_primary' => '484-808-2225',
    ]);
    $boyertown = Location::factory()->for($site)->create(['name' => 'Boyertown']);
    LocationNapProfile::factory()->for($site)->create([
        'location_id' => $boyertown->id, 'business_name' => 'Sump Pump Gurus',
        'address_1' => '9 Boyertown Ave', 'city' => 'Boyertown', 'state' => 'PA', 'phone_primary' => '610-555-1234',
    ]);
    Directory::factory()->create(['domain' => 'yelp.com', 'is_active' => true]);

    return [$site, $trooper, $boyertown];
}

test('verification confirms the scanned location\'s own listing as present', function (): void {
    [, $trooper] = twoLocationTenant();

    $scanner = new CitationScanner(
        verDfs([['position' => 1, 'url' => 'https://www.yelp.com/biz/sump-pump-gurus-trooper', 'domain' => 'www.yelp.com']]),
        verifier: fakeVerifier(fn (string $url): VerifiedListing => new VerifiedListing(phone: '+14848082225')),
    );

    $scanner->scanLocation($trooper);

    $status = CitationStatus::query()->where('location_id', $trooper->id)->first();
    expect($status->presence)->toBe(CitationPresence::PresentMatch);
});

test('a sibling location\'s listing is not claimed by the scanned location', function (): void {
    [, $trooper] = twoLocationTenant();

    // The found Yelp page is Boyertown's (its phone) — must NOT read as Trooper's.
    $scanner = new CitationScanner(
        verDfs([['position' => 1, 'url' => 'https://www.yelp.com/biz/sump-pump-gurus-boyertown', 'domain' => 'www.yelp.com']]),
        verifier: fakeVerifier(fn (string $url): VerifiedListing => new VerifiedListing(phone: '+16105551234')),
    );

    $scanner->scanLocation($trooper);

    $status = CitationStatus::query()->where('location_id', $trooper->id)->first();
    expect($status->presence)->toBe(CitationPresence::Absent);
});

test('without verification a multi-location listing stays unattributed (needs review)', function (): void {
    [, $trooper] = twoLocationTenant();

    // Default NullListingVerifier → no NAP → can't tell siblings apart → Unknown + needs_review.
    $scanner = new CitationScanner(
        verDfs([['position' => 1, 'url' => 'https://www.yelp.com/biz/sump-pump-gurus', 'domain' => 'www.yelp.com']]),
    );

    $scanner->scanLocation($trooper);

    $status = CitationStatus::query()->where('location_id', $trooper->id)->first();
    expect($status->presence)->toBe(CitationPresence::Unknown)
        ->and($status->needs_review)->toBeTrue();
});
