<?php

use App\Enums\UserRole;
use App\Filament\Pages\Citations\CitationsBoard;
use App\Jobs\RunCitationScan;
use App\Models\Location;
use App\Models\LocationNapProfile;
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

test('launch scan is refused for a location without a NAP profile', function (): void {
    $bare = Location::factory()->create(['site_id' => $this->site->id, 'name' => 'No NAP', 'gbp_url' => 'https://g/?cid=2']);

    Livewire::test(CitationsBoard::class)->call('launchScan', $bare->id);

    Queue::assertNotPushed(RunCitationScan::class);
});

test('scan all fans out to every NAP-profiled location', function (): void {
    Livewire::test(CitationsBoard::class)->call('scanAll');

    Queue::assertPushed(RunCitationScan::class, 1);
});
