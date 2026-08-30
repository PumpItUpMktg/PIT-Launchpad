<?php

use App\Enums\UserRole;
use App\Filament\Pages\Citations\CitationsBoard;
use App\Jobs\RunCitationScan;
use App\Models\Location;
use App\Models\LocationNapProfile;
use App\Models\Scopes\SiteScope;
use App\Models\Site;
use App\Models\User;
use Filament\Facades\Filament;
use Illuminate\Support\Facades\Queue;
use Livewire\Livewire;

beforeEach(function (): void {
    Queue::fake();
    Filament::setCurrentPanel('admin');
    $this->actingAs(User::factory()->create(['role' => UserRole::Operator]));
    $this->site = Site::factory()->create(['brand_name' => 'Sump Pump Gurus']);
    $this->location = Location::factory()->create(['site_id' => $this->site->id, 'name' => 'Bedminster', 'gbp_url' => 'https://g/?cid=1']);
    LocationNapProfile::factory()->create(['site_id' => $this->site->id, 'location_id' => $this->location->id, 'business_name' => 'ACME', 'categories' => null]);
});

test('the board renders the tenant location cards', function (): void {
    Livewire::test(CitationsBoard::class)
        ->assertOk()
        ->assertSee('Bedminster');
});

test('launch scan queues a scan for the location', function (): void {
    Livewire::test(CitationsBoard::class)->call('launchScan', $this->location->id);

    Queue::assertPushed(RunCitationScan::class, fn (RunCitationScan $job): bool => $job->locationId === (string) $this->location->id);
});

test('launch scan builds the NAP from the GBP when the location has none', function (): void {
    $gbp = Location::factory()->create([
        'site_id' => $this->site->id,
        'name' => 'Somerville',
        'phone' => '+15125550142',
        'place_id' => 'ChIJMOCK00000000000000000000',
        'gbp_url' => 'https://g/?cid=9',
        'address_components' => [
            ['long_name' => '500', 'types' => ['street_number']],
            ['long_name' => 'West 2nd Street', 'types' => ['route']],
            ['long_name' => 'Austin', 'types' => ['locality']],
            ['long_name' => 'Texas', 'short_name' => 'TX', 'types' => ['administrative_area_level_1']],
            ['long_name' => '78701', 'types' => ['postal_code']],
        ],
    ]);

    Livewire::test(CitationsBoard::class)->call('launchScan', $gbp->id);

    expect(LocationNapProfile::query()->withoutGlobalScope(SiteScope::class)->where('location_id', $gbp->id)->exists())->toBeTrue();
    Queue::assertPushed(RunCitationScan::class, fn (RunCitationScan $job): bool => $job->locationId === (string) $gbp->id);
});

test('launch scan is refused for a location with no usable GBP data to build a NAP', function (): void {
    // gbp_url only — no place_id / address components, so no canonical NAP can be derived.
    $bare = Location::factory()->create(['site_id' => $this->site->id, 'name' => 'No NAP', 'gbp_url' => 'https://g/?cid=2']);

    Livewire::test(CitationsBoard::class)->call('launchScan', $bare->id);

    Queue::assertNotPushed(RunCitationScan::class, fn (RunCitationScan $job): bool => $job->locationId === (string) $bare->id);
    expect(LocationNapProfile::query()->withoutGlobalScope(SiteScope::class)->where('location_id', $bare->id)->exists())->toBeFalse();
});

test('scan all fans out to every NAP-profiled location', function (): void {
    Livewire::test(CitationsBoard::class)->call('scanAll');

    Queue::assertPushed(RunCitationScan::class, 1);
});
