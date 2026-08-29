<?php

use App\Enums\UserRole;
use App\Filament\Resources\LocationNapProfileResource\Pages\ListLocationNapProfiles;
use App\Jobs\RunCitationScan;
use App\Models\Location;
use App\Models\LocationNapProfile;
use App\Models\Site;
use App\Models\User;
use App\Support\CurrentSite;
use Filament\Facades\Filament;
use Illuminate\Support\Facades\Queue;
use Livewire\Livewire;

beforeEach(function (): void {
    Queue::fake();
    Filament::setCurrentPanel('admin');
    $this->actingAs(User::factory()->create(['role' => UserRole::Operator]));
});

test('the Run scan row action queues a citation scan for the location', function (): void {
    $site = Site::factory()->create();
    CurrentSite::set($site->id);
    $location = Location::factory()->for($site)->create();
    $profile = LocationNapProfile::factory()->for($site)->create(['location_id' => $location->id]);

    Livewire::test(ListLocationNapProfiles::class)->callTableAction('scan', $profile);

    Queue::assertPushed(RunCitationScan::class, fn (RunCitationScan $job): bool => $job->locationId === (string) $location->id);
});
