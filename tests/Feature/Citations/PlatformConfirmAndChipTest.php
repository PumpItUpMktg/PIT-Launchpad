<?php

use App\Citations\PlatformCitationConfirmer;
use App\Citations\Ui\CitationChip;
use App\Enums\CitationPresence;
use App\Enums\CitationSource;
use App\Models\CitationStatus;
use App\Models\Directory;
use App\Models\Location;
use App\Models\LocationNapProfile;
use App\Models\Scopes\SiteScope;
use App\Models\Site;
use App\Support\CurrentSite;

// --- Platform (GBP) confirmation -----------------------------------------------------------------------

function gbpConfirmSite(): Site
{
    $site = Site::factory()->create();
    CurrentSite::set($site->id);

    return $site;
}

test('a GBP-backed location gets Google Business Profile confirmed as Live from its own data', function (): void {
    $site = gbpConfirmSite();
    $location = Location::factory()->for($site)->create(['place_id' => 'ChIJTROOPER', 'gbp_url' => 'https://maps.google.com/?cid=9']);
    $gbp = Directory::factory()->create(['domain' => 'google.com', 'is_active' => true]);

    expect(app(PlatformCitationConfirmer::class)->confirm($location))->toBe(1);

    $status = CitationStatus::query()->withoutGlobalScope(SiteScope::class)
        ->where('location_id', $location->id)->where('directory_id', $gbp->id)->first();

    expect($status)->not->toBeNull()
        ->and($status->presence)->toBe(CitationPresence::PresentMatch)
        ->and($status->source)->toBe(CitationSource::Platform)
        ->and($status->attribution_confidence)->toBe(100)
        ->and($status->needs_review)->toBeFalse();
});

test('a location with no GBP is not confirmed', function (): void {
    $site = gbpConfirmSite();
    $location = Location::factory()->for($site)->create(['place_id' => null, 'gbp_url' => null]);
    Directory::factory()->create(['domain' => 'google.com', 'is_active' => true]);

    expect(app(PlatformCitationConfirmer::class)->confirm($location))->toBe(0)
        ->and(CitationStatus::query()->withoutGlobalScope(SiteScope::class)->where('location_id', $location->id)->exists())->toBeFalse();
});

test('confirmation is a no-op when the GBP directory is not in the catalog', function (): void {
    $site = gbpConfirmSite();
    $location = Location::factory()->for($site)->create(['place_id' => 'ChIJX', 'gbp_url' => 'https://g/?cid=1']);

    expect(app(PlatformCitationConfirmer::class)->confirm($location))->toBe(0);
});

// --- Chip relabel --------------------------------------------------------------------------------------

test('a found-but-unattributed listing reads "Needs review", not "Not scanned"', function (): void {
    $site = gbpConfirmSite();
    $location = Location::factory()->for($site)->create();
    LocationNapProfile::factory()->for($site)->create(['location_id' => $location->id]);
    $dir = Directory::factory()->create(['domain' => 'yelp.com', 'is_active' => true]);
    $status = CitationStatus::factory()->create([
        'site_id' => $site->id, 'location_id' => $location->id, 'directory_id' => $dir->id,
        'presence' => CitationPresence::Unknown, 'needs_review' => true,
        'found_url' => 'https://www.yelp.com/biz/x',
    ]);

    $chip = CitationChip::for($status, true);

    expect($chip['key'])->toBe('needs_review')
        ->and($chip['label'])->toBe('Needs review');
});

test('an unknown status with no found URL still reads "Not scanned"', function (): void {
    $site = gbpConfirmSite();
    $location = Location::factory()->for($site)->create();
    $dir = Directory::factory()->create(['domain' => 'manta.com', 'is_active' => true]);
    $status = CitationStatus::factory()->create([
        'site_id' => $site->id, 'location_id' => $location->id, 'directory_id' => $dir->id,
        'presence' => CitationPresence::Unknown, 'found_url' => null,
    ]);

    expect(CitationChip::for($status, true)['key'])->toBe('not_scanned');
});
