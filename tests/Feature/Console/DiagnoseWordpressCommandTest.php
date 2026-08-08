<?php

use Illuminate\Support\Facades\Http;

it('diagnose-wordpress reports a stripped Authorization header', function () {
    Http::fake(['*/wp-json/launchpad/v1/auth-check' => Http::response([
        'authorization_received' => false, 'scheme' => 'none',
        'is_ssl' => true, 'application_passwords_available' => true, 'plugin_version' => '0.9.32',
    ], 200)]);

    $this->artisan('launchpad:diagnose-wordpress', ['site' => 'sandhogworks.com'])
        ->expectsOutputToContain('header is being STRIPPED')
        ->assertExitCode(1);
});

it('diagnose-wordpress confirms the header is delivered', function () {
    Http::fake(['*/wp-json/launchpad/v1/auth-check' => Http::response([
        'authorization_received' => true, 'scheme' => 'basic', 'username' => 'launchpad-sync',
        'is_ssl' => true, 'application_passwords_available' => true, 'plugin_version' => '0.9.32',
    ], 200)]);

    $this->artisan('launchpad:diagnose-wordpress', ['site' => 'sandhogworks.com'])
        ->assertExitCode(0);
});

it('diagnose-wordpress flags a missing/old auth-check endpoint (404)', function () {
    Http::fake(['*/wp-json/launchpad/v1/auth-check' => Http::response('', 404)]);

    $this->artisan('launchpad:diagnose-wordpress', ['site' => 'sandhogworks.com'])
        ->expectsOutputToContain("didn't answer")
        ->assertExitCode(1);
});

it('with a real password: header arrives but the Application Password is REJECTED (401)', function () {
    Http::fake([
        '*/wp-json/launchpad/v1/auth-check' => Http::response(['authorization_received' => true, 'scheme' => 'basic', 'application_passwords_available' => true], 200),
        '*/wp-json/launchpad/v1/status' => Http::response(['data' => ['status' => 401]], 401),
    ]);

    $this->artisan('launchpad:diagnose-wordpress', ['site' => 'sandhogworks.com', '--password' => 'wrongpass1234'])
        ->expectsOutputToContain('REJECTED this Application Password')
        ->assertExitCode(1);
});

it('with a real password: all green when the credential authenticates (200)', function () {
    Http::fake([
        '*/wp-json/launchpad/v1/auth-check' => Http::response(['authorization_received' => true, 'scheme' => 'basic', 'application_passwords_available' => true], 200),
        '*/wp-json/launchpad/v1/status' => Http::response(['ok' => true], 200),
    ]);

    $this->artisan('launchpad:diagnose-wordpress', ['site' => 'sandhogworks.com', '--password' => 'goodpass1234'])
        ->expectsOutputToContain('ALL GREEN')
        ->assertExitCode(0);
});

it('with a real password: authenticated but missing capability (403)', function () {
    Http::fake([
        '*/wp-json/launchpad/v1/auth-check' => Http::response(['authorization_received' => true, 'scheme' => 'basic', 'application_passwords_available' => true], 200),
        '*/wp-json/launchpad/v1/status' => Http::response(['data' => ['status' => 403]], 403),
    ]);

    $this->artisan('launchpad:diagnose-wordpress', ['site' => 'sandhogworks.com', '--password' => 'goodpass1234'])
        ->expectsOutputToContain('lp_manage_content')
        ->assertExitCode(1);
});
