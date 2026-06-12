<?php

use App\Enums\UserRole;
use App\Filament\Resources\LocationResource\Pages\CreateLocation;
use App\Filament\Resources\LocationResource\Pages\EditLocation;
use App\Models\Location;
use App\Models\Site;
use App\Models\User;
use App\Support\BusinessHours;
use Filament\Facades\Filament;
use Livewire\Livewire;

beforeEach(function () {
    Filament::setCurrentPanel('admin');
    $this->actingAs(User::factory()->create(['role' => UserRole::Operator]));
});

it('persists hours as the DAY-KEYED map through create (24h / open-close / closed)', function () {
    $site = Site::factory()->create();
    $fields = BusinessHours::toFields([
        'mon' => '24h',
        'tue' => ['open' => '08:00', 'close' => '17:00'],
        // wed–sun absent → closed
    ]);

    Livewire::test(CreateLocation::class)
        ->fillForm(['site_id' => $site->id, 'name' => 'Round Trip Co', ...$fields])
        ->call('create')
        ->assertHasNoFormErrors();

    $hours = Location::where('name', 'Round Trip Co')->firstOrFail()->hours;

    // Day-keyed, not the numeric [0 => …] the repeater used to write.
    expect($hours['mon'])->toBe('24h')
        ->and($hours['tue'])->toBe(['open' => '08:00', 'close' => '17:00'])
        ->and($hours['wed'])->toBe('closed')
        ->and(array_keys($hours))->toBe(['mon', 'tue', 'wed', 'thu', 'fri', 'sat', 'sun']);
});

it('does NOT clobber stored hours on an edit-save (reopen → save round-trip)', function () {
    $site = Site::factory()->create();
    $stored = [
        'mon' => '24h',
        'tue' => ['open' => '09:00', 'close' => '18:00'],
        'wed' => 'closed', 'thu' => 'closed', 'fri' => 'closed', 'sat' => 'closed', 'sun' => 'closed',
    ];
    $location = Location::factory()->create(['site_id' => $site->id, 'hours' => $stored]);

    // The exact failure: open Edit (hydrate) then Save — used to overwrite with all-closed.
    Livewire::test(EditLocation::class, ['record' => $location->id])
        ->assertFormSet([
            'hours_mon_state' => '24h',
            'hours_tue_state' => 'open',
            'hours_tue_open' => '09:00',
            'hours_tue_close' => '18:00',
            'hours_wed_state' => 'closed',
        ])
        ->call('save')
        ->assertHasNoFormErrors();

    expect($location->fresh()->hours)->toBe($stored); // intact
});

it('normalizes legacy numeric-keyed hours back to the day-keyed map', function () {
    // The broken shape: positional Mon..Sun values.
    $legacy = ['24h', ['open' => '08:00', 'close' => '17:00'], 'closed', 'closed', 'closed', 'closed', 'closed'];

    expect(BusinessHours::normalize($legacy))->toBe([
        'mon' => '24h',
        'tue' => ['open' => '08:00', 'close' => '17:00'],
        'wed' => 'closed', 'thu' => 'closed', 'fri' => 'closed', 'sat' => 'closed', 'sun' => 'closed',
    ]);

    // And a correct map passes through unchanged.
    $good = ['mon' => '24h', 'sun' => 'closed'];
    expect(BusinessHours::normalize($good))->toBe($good);
});
