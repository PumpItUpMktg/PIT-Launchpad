<?php

use App\Citations\CompetitorCitationSeeder;
use App\Citations\DirectoryCandidateHarvester;
use App\Integrations\DataForSeo\DataForSeoClient;
use App\Models\CitationFoundDomain;
use App\Models\Directory;
use App\Models\Location;
use App\Models\LocationNapProfile;
use App\Models\Site;
use App\Support\CurrentSite;

/** @param list<array{position: int, url: string, domain: string}> $organic */
function competitorDfs(array $organic): DataForSeoClient
{
    return new class($organic) extends DataForSeoClient
    {
        /** @param list<array{position: int, url: string, domain: string}> $organic */
        public function __construct(private array $organic) {}

        public function liveOrganic(string $keyword, int $locationCode, string $language, int $depth): array
        {
            return $this->organic;
        }
    };
}

beforeEach(function (): void {
    $this->site = Site::factory()->create();
    CurrentSite::set($this->site->id);
    $this->location = Location::factory()->for($this->site)->create();
    LocationNapProfile::factory()->for($this->site)->create(['location_id' => $this->location->id, 'city' => 'Clifton']);
});

test('seeding persists unmatched competitor domains as candidates and skips the rest', function (): void {
    Directory::factory()->create(['domain' => 'yelp.com', 'is_active' => true]); // already cataloged

    $seeder = new CompetitorCitationSeeder(competitorDfs([
        ['position' => 1, 'url' => 'https://acme-rival.com', 'domain' => 'acme-rival.com'],        // competitor's own site
        ['position' => 2, 'url' => 'https://www.yelp.com/biz/rival', 'domain' => 'www.yelp.com'],    // cataloged
        ['position' => 3, 'url' => 'https://facebook.com/rival', 'domain' => 'facebook.com'],        // non-directory
        ['position' => 4, 'url' => 'https://localbizlist.com/rival', 'domain' => 'localbizlist.com'], // NEW candidate
        ['position' => 5, 'url' => 'https://njcontractors.org/rival', 'domain' => 'njcontractors.org'], // NEW candidate
    ]));

    $tally = $seeder->seed($this->location, 'ACME Rival', 'acme-rival.com');

    expect($tally['seen'])->toBe(5)
        ->and($tally['matched'])->toBe(1)
        ->and($tally['candidates'])->toBe(2);

    $domains = CitationFoundDomain::query()->where('location_id', $this->location->id)->whereNull('directory_id')->pluck('domain')->all();
    expect($domains)->toContain('localbizlist.com')->toContain('njcontractors.org')
        ->not->toContain('acme-rival.com')->not->toContain('facebook.com')->not->toContain('yelp.com');
});

test('seeded candidates flow into the directory candidate harvester', function (): void {
    $seeder = new CompetitorCitationSeeder(competitorDfs([
        ['position' => 1, 'url' => 'https://localbizlist.com/rival', 'domain' => 'localbizlist.com'],
    ]));
    $seeder->seed($this->location, 'ACME Rival');

    $candidates = (new DirectoryCandidateHarvester)->harvest();

    expect($candidates->pluck('domain')->all())->toContain('localbizlist.com');
});

test('the seed command reports its tally', function (): void {
    app()->instance(DataForSeoClient::class, competitorDfs([
        ['position' => 1, 'url' => 'https://localbizlist.com/rival', 'domain' => 'localbizlist.com'],
    ]));

    $this->artisan('launchpad:seed-competitor-citations', [
        '--location' => $this->location->id, '--competitor' => 'ACME Rival',
    ])->assertSuccessful();

    expect(CitationFoundDomain::query()->where('location_id', $this->location->id)->where('domain', 'localbizlist.com')->exists())->toBeTrue();
});
