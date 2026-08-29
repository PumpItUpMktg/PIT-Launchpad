<?php

use App\Enums\DirectoryScope;
use App\Integrations\DataForSeo\DataForSeoClient;
use App\Jobs\RunCitationScan;
use App\Models\CitationScanRun;
use App\Models\Directory;
use App\Models\Location;
use App\Models\LocationNapProfile;
use App\Models\Site;
use App\Support\CurrentSite;

/** @param list<array{position: int, url: string, domain: string}> $organic */
function bindFakeDfs(array $organic): void
{
    app()->instance(DataForSeoClient::class, new class($organic) extends DataForSeoClient
    {
        /** @param list<array{position: int, url: string, domain: string}> $organic */
        public function __construct(private array $organic) {}

        public function liveOrganic(string $keyword, int $locationCode, string $language, int $depth): array
        {
            return $this->organic;
        }
    });
}

test('the scan job records a run with a coverage snapshot and score', function (): void {
    $site = Site::factory()->create();
    CurrentSite::set($site->id);
    $location = Location::factory()->for($site)->create();
    LocationNapProfile::factory()->for($site)->create([
        'location_id' => $location->id, 'business_name' => 'ACME Plumbing', 'categories' => null,
    ]);
    Directory::factory()->create(['domain' => 'yelp.com', 'scope' => DirectoryScope::National, 'seo_value' => 60]);

    bindFakeDfs([['position' => 1, 'url' => 'https://yelp.com/biz/acme', 'domain' => 'yelp.com']]);

    $job = new RunCitationScan($location->id, sweepSharedNumbers: false, trigger: 'manual');
    app()->call([$job, 'handle']);

    $run = CitationScanRun::query()->where('location_id', $location->id)->first();
    expect($run)->not->toBeNull()
        ->and($run->trigger)->toBe('manual')
        ->and($run->finished_at)->not->toBeNull()
        ->and($run->directories_evaluated)->toBe(1)   // yelp status written by the scan
        ->and($run->score)->toBe(50);                 // present-unconfirmed (unverified) = half credit
});
