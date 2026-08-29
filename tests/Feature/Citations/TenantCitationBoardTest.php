<?php

use App\Citations\Ui\TenantCitationBoard;
use App\Enums\CitationLifecycleState;
use App\Enums\CitationPresence;
use App\Enums\DirectoryScope;
use App\Models\CitationScanRun;
use App\Models\CitationStatus;
use App\Models\Directory;
use App\Models\Location;
use App\Models\LocationNapProfile;
use App\Models\Site;
use App\Support\CurrentSite;

beforeEach(function (): void {
    $this->site = Site::factory()->create();
    CurrentSite::set($this->site->id);
    $this->board = new TenantCitationBoard;
});

test('a location card breaks coverage down across its eligible directories', function (): void {
    $location = Location::factory()->for($this->site)->create(['name' => 'Bedminster', 'is_storefront' => true, 'gbp_url' => 'https://g/?cid=1']);
    LocationNapProfile::factory()->for($this->site)->create(['location_id' => $location->id, 'business_name' => 'ACME', 'categories' => null]);

    $dirs = collect(range(1, 4))->map(fn (): Directory => Directory::factory()->create(['scope' => DirectoryScope::National]));
    CitationStatus::factory()->for($this->site)->create(['location_id' => $location->id, 'directory_id' => $dirs[0]->id, 'presence' => CitationPresence::PresentMatch]);
    CitationStatus::factory()->for($this->site)->create(['location_id' => $location->id, 'directory_id' => $dirs[1]->id, 'presence' => CitationPresence::PresentMismatch]);
    CitationStatus::factory()->for($this->site)->create(['location_id' => $location->id, 'directory_id' => $dirs[2]->id, 'presence' => CitationPresence::Absent, 'lifecycle' => CitationLifecycleState::Submitted]);
    // $dirs[3] has no status → missing.

    $card = $this->board->forSite($this->site)[0];

    expect($card->name)->toBe('Bedminster')
        ->and($card->typeLabel)->toBe('Storefront')
        ->and($card->hasGbp)->toBeTrue()
        ->and($card->eligible)->toBe(4)
        ->and($card->live)->toBe(1)
        ->and($card->mismatch)->toBe(1)
        ->and($card->submitted)->toBe(1)
        ->and($card->missing)->toBe(1)
        ->and($card->coveragePercent)->toBe(25);
});

test('scan state reflects the run ledger', function (): void {
    $never = Location::factory()->for($this->site)->create(['name' => 'A never']);
    $scanned = Location::factory()->for($this->site)->create(['name' => 'B scanned']);
    $scanning = Location::factory()->for($this->site)->create(['name' => 'C scanning']);
    CitationScanRun::factory()->for($this->site)->create(['location_id' => $scanned->id, 'finished_at' => now()]);
    CitationScanRun::factory()->for($this->site)->create(['location_id' => $scanning->id, 'finished_at' => null]);

    $cards = collect($this->board->forSite($this->site))->keyBy('name');

    expect($cards['A never']->scanState)->toBe('never')
        ->and($cards['B scanned']->scanState)->toBe('scanned')
        ->and($cards['C scanning']->scanState)->toBe('scanning')
        ->and($cards['C scanning']->isScanning())->toBeTrue();
});

test('a location without a GBP is flagged for attachment', function (): void {
    Location::factory()->for($this->site)->create(['name' => 'No GBP', 'gbp_url' => null]);

    $card = $this->board->forSite($this->site)[0];

    expect($card->hasGbp)->toBeFalse();
});
