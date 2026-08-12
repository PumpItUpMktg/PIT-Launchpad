<?php

use App\JobCapture\Auth\DeviceAuthenticator;
use App\Models\TechDevice;

test('it issues a 6-digit login code and redeems it once for a device token', function () {
    $device = TechDevice::factory()->create();
    $auth = app(DeviceAuthenticator::class);

    $code = $auth->issueLoginCode($device);
    expect($code)->toMatch('/^\d{6}$/');

    $token = $auth->redeemLoginCode($device->fresh(), $code);
    expect($token)->toBeString()
        ->and(strlen((string) $token))->toBeGreaterThanOrEqual(40);

    // The code is one-time — a second redemption fails.
    expect($auth->redeemLoginCode($device->fresh(), $code))->toBeNull();
});

test('it resolves a device token back to its device (tenant-agnostic lookup)', function () {
    $device = TechDevice::factory()->create();
    $auth = app(DeviceAuthenticator::class);
    $code = $auth->issueLoginCode($device);
    $token = $auth->redeemLoginCode($device->fresh(), $code);

    $resolved = $auth->resolveToken((string) $token);

    expect($resolved)->not->toBeNull()
        ->and($resolved->id)->toBe($device->id);
});

test('a wrong or expired code never issues a token', function () {
    $device = TechDevice::factory()->create();
    $auth = app(DeviceAuthenticator::class);

    $code = $auth->issueLoginCode($device);
    $wrong = $code === '000000' ? '111111' : '000000';
    expect($auth->redeemLoginCode($device->fresh(), $wrong))->toBeNull();

    // Force the (correct) code past its expiry — it still fails.
    $device->forceFill(['login_code_expires_at' => now()->subMinute()])->save();
    expect($auth->redeemLoginCode($device->fresh(), $code))->toBeNull();
});

test('a revoked device resolves to null even with a valid token', function () {
    $device = TechDevice::factory()->create();
    $auth = app(DeviceAuthenticator::class);
    $code = $auth->issueLoginCode($device);
    $token = (string) $auth->redeemLoginCode($device->fresh(), $code);

    $device->forceFill(['revoked_at' => now()])->save();

    expect($auth->resolveToken($token))->toBeNull();
});
