<?php

use App\Enums\UserRole;
use App\Filament\Console\Pages\CaptureDevices;
use App\Mail\TechCaptureInviteMail;
use App\Models\Site;
use App\Models\TechDevice;
use App\Models\User;
use Illuminate\Support\Facades\Mail;
use Livewire\Livewire;

beforeEach(fn () => $this->actingAs(User::factory()->create())); // Operator (Super Admin) by default

it('is reachable only by the Super Admin tier', function () {
    expect(CaptureDevices::canAccess())->toBeTrue();

    $this->actingAs(User::factory()->siteAdmin()->create());
    expect(CaptureDevices::canAccess())->toBeFalse();
});

it('adds a tech device and surfaces a capture link + one-time code', function () {
    $site = Site::factory()->create();

    $page = new CaptureDevices;
    $page->siteId = $site->id;
    $page->newName = 'Mike R.';
    $page->newPhone = '+15551234567';
    $page->addDevice();

    $device = TechDevice::withoutGlobalScopes()->where('site_id', $site->id)->first();

    expect($device)->not->toBeNull()
        ->and($device->name)->toBe('Mike R.')
        ->and($page->lastIssued['code'])->toMatch('/^\d{6}$/')
        ->and($page->lastIssued['link'])->toContain('/capture?device='.$device->id)
        ->and($page->newName)->toBe('')   // form cleared
        ->and(collect($page->getDevicesProperty())->pluck('id'))->toContain($device->id);

    // The tech is now a first-class platform account (unified identity): device → role=tech User.
    expect($device->user_id)->not->toBeNull()
        ->and($device->user->role)->toBe(UserRole::Tech);
});

it('emails the invite on add when an email is given, and resends it on reissue', function () {
    Mail::fake();
    $site = Site::factory()->create();

    $page = new CaptureDevices;
    $page->siteId = $site->id;
    $page->newName = 'Emailed Tech';
    $page->newEmail = 'et@example.com';
    $page->addDevice();

    Mail::assertSent(TechCaptureInviteMail::class, fn (TechCaptureInviteMail $m): bool => $m->hasTo('et@example.com'));

    $device = TechDevice::withoutGlobalScopes()->where('site_id', $site->id)->first();
    $page->reissueCode($device->id);

    Mail::assertSent(TechCaptureInviteMail::class, 2); // once on add, once on resend
});

it('re-issues a fresh code for an existing device', function () {
    $site = Site::factory()->create();
    $device = TechDevice::factory()->create(['site_id' => $site->id]);

    $page = new CaptureDevices;
    $page->siteId = $site->id;
    $page->reissueCode($device->id);

    expect($page->lastIssued['code'])->toMatch('/^\d{6}$/')
        ->and($device->refresh()->login_code_hash)->not->toBeNull();
});

it('revokes a device', function () {
    $site = Site::factory()->create();
    $device = TechDevice::factory()->create(['site_id' => $site->id]);

    $page = new CaptureDevices;
    $page->siteId = $site->id;
    $page->revoke($device->id);

    expect($device->refresh()->revoked_at)->not->toBeNull();
});

it('renders the page (compiles the blade)', function () {
    $site = Site::factory()->create();
    TechDevice::factory()->create(['site_id' => $site->id, 'name' => 'Mike R.']);

    Livewire::test(CaptureDevices::class)
        ->set('siteId', $site->id)
        ->assertOk()
        ->assertSee('Mike R.');
});
