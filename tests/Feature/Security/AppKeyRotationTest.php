<?php

use App\Models\Connection;
use App\Models\Site;
use App\Security\AppKeyRotator;
use Illuminate\Contracts\Encryption\DecryptException;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Encryption\Encrypter;

afterEach(function () {
    // Restore the default (config-keyed) encrypter for other tests.
    Model::encryptUsing(app('encrypter'));
});

test('rotating the app key re-encrypts every connection and they still decrypt', function () {
    $site = Site::factory()->create();

    $secrets = [
        Connection::factory()->create(['site_id' => $site->id, 'provider' => 'wp_app_password', 'credentials' => ['password' => 'wp-secret-1']]),
        Connection::factory()->create(['site_id' => $site->id, 'provider' => 'gbp', 'credentials' => ['token' => 'gbp-secret-2']]),
        Connection::factory()->create(['site_id' => $site->id, 'provider' => 'ga4', 'credentials' => ['token' => 'ga4-secret-3']]),
    ];

    $rotator = new AppKeyRotator;
    $cipher = (string) config('app.cipher', 'AES-256-CBC');

    $old = $rotator->encrypterForKey((string) config('app.key'));
    $new = $rotator->encrypterForKey('base64:'.base64_encode(Encrypter::generateKey($cipher)));

    $count = $rotator->reencryptConnections($old, $new);
    expect($count)->toBe(3);

    // Under the new key, every connection still decrypts to the original plaintext.
    Model::encryptUsing($new);
    expect($secrets[0]->fresh()->credentials)->toBe(['password' => 'wp-secret-1'])
        ->and($secrets[1]->fresh()->credentials)->toBe(['token' => 'gbp-secret-2'])
        ->and($secrets[2]->fresh()->credentials)->toBe(['token' => 'ga4-secret-3']);
});

test('after re-encryption the old key can no longer decrypt the credentials', function () {
    $site = Site::factory()->create();
    $connection = Connection::factory()->create(['site_id' => $site->id, 'provider' => 'gbp', 'credentials' => ['token' => 'secret']]);

    $rotator = new AppKeyRotator;
    $cipher = (string) config('app.cipher', 'AES-256-CBC');
    $old = $rotator->encrypterForKey((string) config('app.key'));
    $new = $rotator->encrypterForKey('base64:'.base64_encode(Encrypter::generateKey($cipher)));

    $rotator->reencryptConnections($old, $new);

    // Old key now fails to read the re-encrypted column.
    Model::encryptUsing($old);
    expect(fn () => $connection->fresh()->credentials)->toThrow(DecryptException::class);
});
