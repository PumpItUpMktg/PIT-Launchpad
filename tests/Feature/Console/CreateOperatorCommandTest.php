<?php

use App\Enums\UserRole;
use App\Models\User;
use Filament\Facades\Filament;
use Illuminate\Support\Facades\Hash;

it('creates an operator that satisfies the operator panel access gate', function () {
    $this->artisan('launchpad:create-operator', [
        'email' => 'Eric@Example.com',
        'password' => 'supersecret123',
    ])->assertSuccessful();

    $user = User::query()->where('email', 'eric@example.com')->firstOrFail(); // normalized lower-case

    expect($user->role)->toBe(UserRole::Operator)
        ->and(Hash::check('supersecret123', $user->password))->toBeTrue()
        ->and($user->canAccessPanel(Filament::getPanel('admin')))->toBeTrue()
        ->and($user->canAccessPanel(Filament::getPanel('client')))->toBeFalse();
});

it('promotes an existing client to operator with --force', function () {
    $client = User::factory()->create(['email' => 'eric@example.com', 'role' => UserRole::Client]);

    $this->artisan('launchpad:create-operator', [
        'email' => 'eric@example.com',
        'password' => 'newsecret123',
        '--force' => true,
    ])->assertSuccessful();

    expect($client->fresh()->role)->toBe(UserRole::Operator)
        ->and(Hash::check('newsecret123', $client->fresh()->password))->toBeTrue();
});

it('refuses to overwrite an existing user without --force', function () {
    User::factory()->create(['email' => 'eric@example.com', 'role' => UserRole::Client]);

    $this->artisan('launchpad:create-operator', [
        'email' => 'eric@example.com',
        'password' => 'newsecret123',
    ])->assertFailed();

    expect(User::query()->where('email', 'eric@example.com')->first()->role)->toBe(UserRole::Client);
});

it('fails when no password is supplied', function () {
    $this->artisan('launchpad:create-operator', ['email' => 'eric@example.com'])->assertFailed();

    expect(User::query()->where('email', 'eric@example.com')->exists())->toBeFalse();
});

it('rejects a weak password', function () {
    $this->artisan('launchpad:create-operator', ['email' => 'eric@example.com', 'password' => 'short'])->assertFailed();

    expect(User::query()->where('email', 'eric@example.com')->exists())->toBeFalse();
});

it('falls back to the LAUNCHPAD_OPERATOR_PASSWORD env var', function () {
    putenv('LAUNCHPAD_OPERATOR_PASSWORD=fromenv12345');
    $_ENV['LAUNCHPAD_OPERATOR_PASSWORD'] = 'fromenv12345';

    try {
        $this->artisan('launchpad:create-operator', ['email' => 'eric@example.com'])->assertSuccessful();
        expect(Hash::check('fromenv12345', User::query()->where('email', 'eric@example.com')->firstOrFail()->password))->toBeTrue();
    } finally {
        putenv('LAUNCHPAD_OPERATOR_PASSWORD');
        unset($_ENV['LAUNCHPAD_OPERATOR_PASSWORD']);
    }
});
