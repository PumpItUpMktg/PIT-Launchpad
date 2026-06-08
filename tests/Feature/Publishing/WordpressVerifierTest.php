<?php

use App\Models\Connection;
use App\Models\Site;
use App\Security\Verification\ConnectionVerifier;
use App\Security\Verification\WordpressConnectionVerifier;
use Illuminate\Support\Facades\Http;

test('the default ConnectionVerifier is now WordPress-backed', function () {
    expect(app(ConnectionVerifier::class))->toBeInstanceOf(WordpressConnectionVerifier::class);
});

test('the WP verifier pings live WordPress with the candidate credential', function () {
    Http::fake(['*/wp-json/wp/v2/users/me' => Http::response(['id' => 1], 200)]);

    $site = Site::factory()->create();
    $connection = Connection::factory()->create([
        'site_id' => $site->id,
        'provider' => 'wp_app_password',
        'credentials' => ['base_url' => 'https://wp.test', 'username' => 'svc', 'app_password' => 'old-pass'],
    ]);

    $verified = app(ConnectionVerifier::class)->verify($connection, [
        'username' => 'svc',
        'app_password' => 'new-pass',
    ]);

    expect($verified)->toBeTrue();

    // Verify-before-revoke pinged with the NEW (candidate) credential.
    Http::assertSent(fn ($r) => $r->hasHeader('Authorization', 'Basic '.base64_encode('svc:new-pass')));
});

test('the WP verifier rejects a candidate the ping refuses', function () {
    Http::fake(['*/wp-json/wp/v2/users/me' => Http::response('', 401)]);

    $site = Site::factory()->create();
    $connection = Connection::factory()->create([
        'site_id' => $site->id,
        'provider' => 'wp_app_password',
        'credentials' => ['base_url' => 'https://wp.test', 'username' => 'svc', 'app_password' => 'old'],
    ]);

    expect(app(ConnectionVerifier::class)->verify($connection, ['username' => 'svc', 'app_password' => 'bad']))
        ->toBeFalse();
});

test('a non-WP provider is accepted permissively until its adapter lands', function () {
    $connection = Connection::factory()->create(['provider' => 'gbp']);

    expect(app(ConnectionVerifier::class)->verify($connection, ['token' => 'anything']))->toBeTrue();
});
