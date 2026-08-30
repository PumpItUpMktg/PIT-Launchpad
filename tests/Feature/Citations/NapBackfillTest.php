<?php

use App\Enums\UserRole;
use App\Filament\Pages\Citations\CitationsPortfolio;
use App\Models\Location;
use App\Models\LocationNapProfile;
use App\Models\Scopes\SiteScope;
use App\Models\Site;
use App\Models\User;
use App\Support\CurrentSite;
use Filament\Facades\Filament;
use Livewire\Livewire;

/** A location carrying the GBP data a NAP can be derived from. */
function backfillGbpLocation(Site $site, string $name): Location
{
    return Location::factory()->create([
        'site_id' => $site->id,
        'name' => $name,
        'phone' => '+15125550142',
        'place_id' => 'ChIJMOCK'.substr(md5($name), 0, 20),
        'gbp_url' => 'https://g/?cid=1',
        'address_components' => [
            ['long_name' => '500', 'types' => ['street_number']],
            ['long_name' => 'West 2nd Street', 'types' => ['route']],
            ['long_name' => 'Austin', 'types' => ['locality']],
            ['long_name' => 'Texas', 'short_name' => 'TX', 'types' => ['administrative_area_level_1']],
            ['long_name' => '78701', 'types' => ['postal_code']],
        ],
    ]);
}

function napExists(Location $location): bool
{
    return LocationNapProfile::query()->withoutGlobalScope(SiteScope::class)
        ->where('location_id', $location->id)->exists();
}

test('the backfill command creates NAPs for GBP-backed locations and skips the rest', function (): void {
    $site = Site::factory()->create();
    CurrentSite::set($site->id);
    $gbp = backfillGbpLocation($site, 'Bedminster');
    $manual = Location::factory()->create(['site_id' => $site->id, 'place_id' => null, 'address_components' => [], 'phone' => null]);

    $this->artisan('launchpad:backfill-naps')
        ->expectsOutputToContain('1 created')
        ->assertSuccessful();

    expect(napExists($gbp))->toBeTrue()
        ->and(napExists($manual))->toBeFalse();
});

test('the backfill command can scope to one site', function (): void {
    $siteA = Site::factory()->create();
    $siteB = Site::factory()->create();
    CurrentSite::set($siteA->id);
    $a = backfillGbpLocation($siteA, 'A location');
    CurrentSite::set($siteB->id);
    $b = backfillGbpLocation($siteB, 'B location');

    $this->artisan('launchpad:backfill-naps', ['--site' => $siteA->id])->assertSuccessful();

    expect(napExists($a))->toBeTrue()
        ->and(napExists($b))->toBeFalse();
});

test('the backfill is idempotent — a second run creates nothing new', function (): void {
    $site = Site::factory()->create();
    CurrentSite::set($site->id);
    backfillGbpLocation($site, 'Bedminster');

    $this->artisan('launchpad:backfill-naps')->assertSuccessful();
    $this->artisan('launchpad:backfill-naps')
        ->expectsOutputToContain('0 created')
        ->assertSuccessful();
});

test('the operator can backfill NAPs from the portfolio page', function (): void {
    Filament::setCurrentPanel('admin');
    $this->actingAs(User::factory()->create(['role' => UserRole::Operator]));
    $site = Site::factory()->create();
    CurrentSite::set($site->id);
    $gbp = backfillGbpLocation($site, 'Bedminster');

    expect(napExists($gbp))->toBeFalse();

    Livewire::test(CitationsPortfolio::class)->callAction('backfillNaps');

    expect(napExists($gbp))->toBeTrue();
});
