<?php

use App\Citations\CitationScanner;
use App\Citations\Ui\TenantCitationBoard;
use App\Enums\CitationPresence;
use App\Enums\CitationSource;
use App\Enums\DirectoryScope;
use App\Integrations\Citations\HttpListingVerifier;
use App\Integrations\DataForSeo\DataForSeoClient;
use App\Integrations\DataForSeo\DataForSeoException;
use App\Jobs\RunCitationScan;
use App\Models\CitationScanRun;
use App\Models\CitationStatus;
use App\Models\Directory;
use App\Models\Location;
use App\Models\LocationNapProfile;
use App\Models\Site;
use App\Support\CurrentSite;
use Illuminate\Support\Facades\Http;

/**
 * A DataForSEO double that answers per query (a closure), so brand queries and per-directory `site:` checks
 * can return different result sets.
 */
function dfsAnswering(Closure $answer): DataForSeoClient
{
    return new class($answer) extends DataForSeoClient
    {
        public function __construct(private Closure $answer) {}

        public function liveOrganic(string $keyword, int $locationCode, string $language, int $depth): array
        {
            return ($this->answer)($keyword);
        }
    };
}

beforeEach(function (): void {
    $this->site = Site::factory()->create();
    CurrentSite::set($this->site->id);
    $this->location = Location::factory()->for($this->site)->create();
    LocationNapProfile::factory()->for($this->site)->create([
        'location_id' => $this->location->id,
        'business_name' => 'ACME Plumbing',
        'address_1' => '123 Main St',
        'city' => 'Clifton',
        'state' => 'NJ',
        'postal' => '07013',
        'phone_primary' => '973-111-1111',
        'categories' => null,
    ]);
});

test('a listing the brand queries never surface is found by the per-directory site: check', function (): void {
    $bbb = Directory::factory()->create(['domain' => 'bbb.org', 'scope' => DirectoryScope::National, 'is_active' => true]);
    $queries = [];

    $scanner = new CitationScanner(dfsAnswering(function (string $q) use (&$queries): array {
        $queries[] = $q;
        if (str_starts_with($q, 'site:bbb.org')) {
            return [
                // The directory's own search page — never a listing.
                ['position' => 1, 'url' => 'https://www.bbb.org/search?find_text=acme', 'domain' => 'www.bbb.org', 'title' => 'Search results'],
                ['position' => 2, 'url' => 'https://www.bbb.org/us/nj/clifton/profile/plumber/acme-plumbing-0221-90001', 'domain' => 'www.bbb.org', 'title' => 'ACME Plumbing | Better Business Bureau Profile'],
            ];
        }

        return []; // brand queries: nothing ranks
    }));

    $scanner->scanLocation($this->location);

    $status = CitationStatus::query()->where('location_id', $this->location->id)->where('directory_id', $bbb->id)->first();
    expect($status)->not->toBeNull()
        ->and($status->presence)->toBe(CitationPresence::PresentMatch)
        ->and($status->found_url)->toContain('/profile/plumber/acme-plumbing')
        ->and(collect($queries)->contains('site:bbb.org "ACME Plumbing" Clifton'))->toBeTrue();
});

test('a site: result that does not name the brand, or is off-domain, is not a listing', function (): void {
    $yp = Directory::factory()->create(['domain' => 'yellowpages.com', 'scope' => DirectoryScope::National, 'is_active' => true]);

    $scanner = new CitationScanner(dfsAnswering(fn (string $q): array => str_starts_with($q, 'site:') ? [
        ['position' => 1, 'url' => 'https://www.yellowpages.com/clifton-nj/plumbers', 'domain' => 'www.yellowpages.com', 'title' => 'Best 30 Plumbers in Clifton, NJ'],
        ['position' => 2, 'url' => 'https://www.yelp.com/biz/acme-plumbing-clifton', 'domain' => 'www.yelp.com', 'title' => 'ACME Plumbing'],
    ] : []));

    $scanner->scanLocation($this->location);

    expect(CitationStatus::query()->where('location_id', $this->location->id)->where('directory_id', $yp->id)->exists())->toBeFalse();
});

test('a previously-present listing the scan stops finding is aged out to Absent after two misses, never a platform-confirmed one', function (): void {
    config(['launchpad.citations.lost_after_misses' => 2]);
    $yelp = Directory::factory()->create(['domain' => 'yelp.com', 'scope' => DirectoryScope::National, 'is_active' => true]);
    $google = Directory::factory()->create(['domain' => 'google.com', 'scope' => DirectoryScope::National, 'is_active' => true]);
    foreach ([[$yelp, CitationSource::Unknown], [$google, CitationSource::Platform]] as [$dir, $source]) {
        CitationStatus::query()->create([
            'site_id' => $this->site->id, 'location_id' => $this->location->id, 'directory_id' => $dir->id,
            'presence' => CitationPresence::PresentMatch, 'source' => $source, 'found_url' => 'https://x/y',
        ]);
    }

    $scanner = new CitationScanner(dfsAnswering(fn (): array => []));   // nothing found at all

    $scanner->scanLocation($this->location);
    $yelpRow = CitationStatus::query()->where('directory_id', $yelp->id)->first();
    expect($yelpRow->presence)->toBe(CitationPresence::PresentMatch)   // one miss is noise
        ->and($yelpRow->missed_scans)->toBe(1);

    $scanner->scanLocation($this->location);
    expect(CitationStatus::query()->where('directory_id', $yelp->id)->first()->presence)->toBe(CitationPresence::Absent)
        ->and(CitationStatus::query()->where('directory_id', $google->id)->first()->presence)->toBe(CitationPresence::PresentMatch)
        ->and(CitationStatus::query()->where('directory_id', $google->id)->first()->missed_scans)->toBe(0);

    // Found again → present, miss count reset.
    $again = new CitationScanner(dfsAnswering(fn (): array => [['position' => 1, 'url' => 'https://www.yelp.com/biz/acme-plumbing', 'domain' => 'www.yelp.com', 'title' => 'ACME Plumbing']]));
    $again->scanLocation($this->location);
    $yelpRow = CitationStatus::query()->where('directory_id', $yelp->id)->first();
    expect($yelpRow->presence)->toBe(CitationPresence::PresentMatch)->and($yelpRow->missed_scans)->toBe(0);
});

test('a scraped full address with the right ZIP is a match; a different phone is a mismatch', function (): void {
    $yelp = Directory::factory()->create(['domain' => 'yelp.com', 'scope' => DirectoryScope::National, 'is_active' => true]);
    $page = fn (string $phone, string $street): string => '<html><script type="application/ld+json">'.json_encode([
        '@type' => 'LocalBusiness', 'name' => 'ACME Plumbing', 'telephone' => $phone,
        'address' => ['streetAddress' => $street, 'addressLocality' => 'Clifton', 'addressRegion' => 'NJ', 'postalCode' => '07013'],
    ]).'</script></html>';
    // First fetch: the right phone; second fetch (the rescan): a wrong phone.
    Http::fake(['yelp.com/*' => Http::sequence()->push($page('(973) 111-1111', '123 Main Street'))->push($page('(973) 999-0000', '123 Main St'))]);
    $dfs = dfsAnswering(fn (): array => [['position' => 1, 'url' => 'https://www.yelp.com/biz/acme-plumbing', 'domain' => 'www.yelp.com', 'title' => 'ACME Plumbing']]);

    (new CitationScanner($dfs, verifier: new HttpListingVerifier))->scanLocation($this->location);
    $row = CitationStatus::query()->where('directory_id', $yelp->id)->first();
    expect($row->presence)->toBe(CitationPresence::PresentMatch)
        ->and($row->found_address)->toBe('123 Main Street, Clifton, NJ, 07013')
        ->and($row->mismatch_fields)->toBeNull();

    (new CitationScanner($dfs, verifier: new HttpListingVerifier))->scanLocation($this->location);
    $row = CitationStatus::query()->where('directory_id', $yelp->id)->first();
    expect($row->presence)->toBe(CitationPresence::PresentMismatch)
        ->and($row->mismatch_fields)->toHaveKey('phone')
        ->and($row->mismatch_fields)->not->toHaveKey('address');
});

test('the verifier prefers the listing\'s LocalBusiness node over the directory\'s own Organization node', function (): void {
    Http::fake(['dir.test/*' => Http::response('<html>'
        .'<script type="application/ld+json">'.json_encode(['@type' => 'Organization', 'name' => 'Big Directory Inc', 'telephone' => '800-000-0000']).'</script>'
        .'<script type="application/ld+json">'.json_encode(['@type' => 'LocalBusiness', 'name' => 'ACME Plumbing', 'telephone' => '973-111-1111', 'address' => ['streetAddress' => '123 Main St']]).'</script>'
        .'</html>')]);

    $listing = (new HttpListingVerifier)->verify('dir.test', 'https://dir.test/acme');

    expect($listing->name)->toBe('ACME Plumbing')->and($listing->phone)->toBe('973-111-1111');
});

test('a scan that throws closes its run as failed with the reason, and the board says so instead of "scanning"', function (): void {
    Directory::factory()->create(['domain' => 'yelp.com', 'scope' => DirectoryScope::National, 'is_active' => true]);
    app()->instance(DataForSeoClient::class, dfsAnswering(fn (): array => throw new DataForSeoException('DataForSEO 401: Unauthorized')));

    $job = new RunCitationScan($this->location->id, sweepSharedNumbers: false, trigger: 'manual');
    expect(fn () => app()->call([$job, 'handle']))->toThrow(DataForSeoException::class);

    $run = CitationScanRun::query()->where('location_id', $this->location->id)->first();
    expect($run->finished_at)->not->toBeNull()
        ->and($run->error)->toContain('401');

    $card = (new TenantCitationBoard)->forSite($this->site)[0];
    expect($card->scanState)->toBe('failed')
        ->and($card->scanFailed())->toBeTrue()
        ->and($card->lastError)->toContain('401');
});

test('an open run older than the stale threshold reads as failed, a fresh one as scanning, and failed() closes it', function (): void {
    config(['launchpad.citations.stale_run_minutes' => 30]);
    $stale = CitationScanRun::factory()->for($this->site)->create(['location_id' => $this->location->id, 'started_at' => now()->subHours(2), 'finished_at' => null]);

    expect((new TenantCitationBoard)->forSite($this->site)[0]->scanState)->toBe('failed');

    CitationScanRun::factory()->for($this->site)->create(['location_id' => $this->location->id, 'started_at' => now()->subMinute(), 'finished_at' => null]);
    expect((new TenantCitationBoard)->forSite($this->site)[0]->scanState)->toBe('scanning');

    (new RunCitationScan($this->location->id))->failed(new RuntimeException('killed'));
    expect(CitationScanRun::query()->where('location_id', $this->location->id)->whereNull('finished_at')->count())->toBe(0)
        ->and($stale->refresh()->error)->toContain('killed');

    // A later clean run clears the failed state.
    CitationScanRun::factory()->for($this->site)->create(['location_id' => $this->location->id, 'started_at' => now(), 'finished_at' => now()]);
    expect((new TenantCitationBoard)->forSite($this->site)[0]->scanState)->toBe('scanned');
});

test('the scan job seeds the national directory catalog when it is empty', function (): void {
    expect(Directory::query()->count())->toBe(0);
    app()->instance(DataForSeoClient::class, dfsAnswering(fn (): array => []));

    $job = new RunCitationScan($this->location->id, sweepSharedNumbers: false, trigger: 'manual');
    app()->call([$job, 'handle']);

    expect(Directory::query()->where('is_active', true)->count())->toBeGreaterThan(10)
        ->and(CitationStatus::query()->where('location_id', $this->location->id)->count())->toBeGreaterThan(0); // reconciled as gaps
});

test('on a multi-location brand, a sibling\'s page ranking first never hides this location\'s own listing', function (): void {
    $newtown = Location::factory()->for($this->site)->create();
    LocationNapProfile::factory()->for($this->site)->create([
        'location_id' => $newtown->id, 'business_name' => 'ACME Plumbing', 'address_1' => '9 Oak Ave',
        'city' => 'Newtown', 'state' => 'PA', 'postal' => '18940', 'phone_primary' => '215-222-2222', 'categories' => null,
    ]);
    $yelp = Directory::factory()->create(['domain' => 'yelp.com', 'scope' => DirectoryScope::National, 'is_active' => true]);

    $page = fn (string $phone, string $city): string => '<html><script type="application/ld+json">'.json_encode([
        '@type' => 'LocalBusiness', 'name' => 'ACME Plumbing', 'telephone' => $phone, 'address' => ['addressLocality' => $city],
    ]).'</script></html>';
    Http::fake([
        'yelp.com/biz/acme-plumbing-newtown' => Http::response($page('215-222-2222', 'Newtown')),
        'yelp.com/biz/acme-plumbing-clifton' => Http::response($page('973-111-1111', 'Clifton')),
    ]);
    // The directory check returns the sibling's page FIRST (no town in title), then this location's.
    $dfs = dfsAnswering(fn (string $q): array => str_starts_with($q, 'site:yelp.com') ? [
        ['position' => 1, 'url' => 'https://www.yelp.com/biz/acme-plumbing-newtown', 'domain' => 'www.yelp.com', 'title' => 'ACME Plumbing - Plumbing'],
        ['position' => 2, 'url' => 'https://www.yelp.com/biz/acme-plumbing-clifton', 'domain' => 'www.yelp.com', 'title' => 'ACME Plumbing - Plumbing'],
    ] : []);

    (new CitationScanner($dfs, verifier: new HttpListingVerifier))->scanLocation($this->location);

    $row = CitationStatus::query()->where('location_id', $this->location->id)->where('directory_id', $yelp->id)->first();
    expect($row->presence)->toBe(CitationPresence::PresentMatch)
        ->and($row->found_url)->toBe('https://www.yelp.com/biz/acme-plumbing-clifton')   // its own page, not the sibling's
        ->and($row->attributed_location_id)->toBe($this->location->id)
        ->and($row->found_phone)->toBe('973-111-1111');
    // The sibling's row was never touched by this location's scan.
    expect(CitationStatus::query()->where('location_id', $newtown->id)->exists())->toBeFalse();
});

test('on a multi-location brand, a directory that blocks scraping is parked for review, never claimed', function (): void {
    $newtown = Location::factory()->for($this->site)->create();
    LocationNapProfile::factory()->for($this->site)->create([
        'location_id' => $newtown->id, 'business_name' => 'ACME Plumbing', 'city' => 'Newtown', 'state' => 'PA', 'phone_primary' => '215-222-2222', 'categories' => null,
    ]);
    $yelp = Directory::factory()->create(['domain' => 'yelp.com', 'scope' => DirectoryScope::National, 'is_active' => true]);
    Http::fake(['yelp.com/*' => Http::response('blocked', 403)]);
    $dfs = dfsAnswering(fn (string $q): array => str_starts_with($q, 'site:yelp.com') ? [
        ['position' => 1, 'url' => 'https://www.yelp.com/biz/acme-plumbing-newtown', 'domain' => 'www.yelp.com', 'title' => 'ACME Plumbing'],
    ] : []);

    (new CitationScanner($dfs, verifier: new HttpListingVerifier))->scanLocation($this->location);

    $row = CitationStatus::query()->where('location_id', $this->location->id)->where('directory_id', $yelp->id)->first();
    expect($row->presence)->toBe(CitationPresence::Unknown)
        ->and($row->needs_review)->toBeTrue()
        ->and($row->attributed_location_id)->toBeNull();
});
