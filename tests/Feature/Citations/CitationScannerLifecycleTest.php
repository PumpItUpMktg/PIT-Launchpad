<?php

use App\Citations\CitationScanner;
use App\Enums\CitationLifecycleState;
use App\Enums\CitationPresence;
use App\Integrations\DataForSeo\DataForSeoClient;
use App\Models\CitationFoundDomain;
use App\Models\CitationStatus;
use App\Models\Directory;
use App\Models\Location;
use App\Models\LocationNapProfile;
use App\Models\Site;
use App\Support\CurrentSite;

test('a scan updates presence but never touches the lifecycle axis', function (): void {
    $site = Site::factory()->create();
    CurrentSite::set($site->id);
    $location = Location::factory()->for($site)->create();
    LocationNapProfile::factory()->for($site)->create(['location_id' => $location->id, 'business_name' => 'ACME', 'categories' => null]);
    $yelp = Directory::factory()->create(['domain' => 'yelp.com', 'is_active' => true]);

    // The operator already submitted this citation; a scan then finds it present.
    CitationStatus::factory()->for($site)->create([
        'location_id' => $location->id, 'directory_id' => $yelp->id,
        'presence' => CitationPresence::Absent, 'lifecycle' => CitationLifecycleState::Submitted,
    ]);

    $scanner = new CitationScanner(new class extends DataForSeoClient
    {
        public function __construct() {}

        public function liveOrganic(string $keyword, int $locationCode, string $language, int $depth): array
        {
            return [['position' => 1, 'url' => 'https://yelp.com/biz/acme', 'domain' => 'yelp.com']];
        }
    });

    $scanner->scanLocation($location);

    // Presence moves to a real listing (the two axes are independent); lifecycle stays Submitted for the
    // verifier to advance, never clobbered by the scan. The found domain is recorded.
    $status = CitationStatus::query()->where('location_id', $location->id)->where('directory_id', $yelp->id)->first();
    expect($status->presence)->toBe(CitationPresence::PresentMatch)
        ->and($status->lifecycle)->toBe(CitationLifecycleState::Submitted)
        ->and(CitationFoundDomain::query()->where('location_id', $location->id)->where('domain', 'yelp.com')->exists())
        ->toBeTrue();
});
