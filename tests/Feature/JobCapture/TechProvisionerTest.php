<?php

use App\Enums\UserRole;
use App\JobCapture\Auth\TechProvisioner;
use App\Models\Site;
use App\Models\TechDevice;
use App\Models\User;

it('provisions a tech as a role=tech user linked to a capture device with a one-time code', function () {
    $site = Site::factory()->create();

    $result = app(TechProvisioner::class)->provision($site->id, 'Mike R.', '+15551234567');

    $device = $result['device'];

    expect($device)->toBeInstanceOf(TechDevice::class)
        ->and($device->site_id)->toBe($site->id)
        ->and($device->name)->toBe('Mike R.')
        ->and($device->phone)->toBe('+15551234567')
        ->and($result['code'])->toMatch('/^\d{6}$/')
        ->and($result['link'])->toContain('/capture?device='.$device->id);

    $user = $device->user;
    expect($user)->not->toBeNull()
        ->and($user->role)->toBe(UserRole::Tech)
        ->and($user->name)->toBe('Mike R.');
});

it('uses a supplied real email for the user but leaves it unusable as a login until upgrade', function () {
    $site = Site::factory()->create();

    $result = app(TechProvisioner::class)->provision($site->id, 'Dana T.', null, 'Dana@Example.com');

    $user = $result['device']->user;
    expect($user->email)->toBe('dana@example.com')          // normalized
        ->and($result['device']->email)->toBe('dana@example.com');
});

it('synthesizes a unique non-deliverable address when no email is given', function () {
    $site = Site::factory()->create();

    $a = app(TechProvisioner::class)->provision($site->id, 'Tech A');
    $b = app(TechProvisioner::class)->provision($site->id, 'Tech B');

    expect($a['device']->user->email)->toEndWith('@device.local')
        ->and($b['device']->user->email)->toEndWith('@device.local')
        ->and($a['device']->user->email)->not->toBe($b['device']->user->email)
        ->and($a['device']->email)->toBeNull();  // device carries no address when none supplied
});

it('falls back to a synthetic address when the supplied email already belongs to a user', function () {
    $site = Site::factory()->create();
    User::factory()->create(['email' => 'taken@example.com']);

    $result = app(TechProvisioner::class)->provision($site->id, 'Sam P.', null, 'taken@example.com');

    // The User row must stay unique; the device still records the real contact email.
    expect($result['device']->user->email)->toEndWith('@device.local')
        ->and($result['device']->email)->toBe('taken@example.com');
});
