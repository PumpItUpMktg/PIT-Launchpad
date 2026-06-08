<?php

namespace App\Security;

use Illuminate\Encryption\Encrypter;
use Illuminate\Support\Facades\DB;

/**
 * Re-encrypts every per-tenant Connection secret from an old APP_KEY to a new
 * one. APP_KEY is the master key for all encrypted-at-rest credentials, so a
 * naïve rotation would make them undecryptable; this walks each row at the raw
 * column level — decrypt-old → re-encrypt-new → save — preserving the exact
 * inner payload, so the new key round-trips losslessly.
 *
 * The class operates purely on supplied Encrypters (no global state), which is
 * what makes the round-trip unit-testable without mutating the process key.
 */
class AppKeyRotator
{
    /**
     * Build an Encrypter for a key string, accepting the `base64:` form Laravel
     * stores in env.
     */
    public function encrypterForKey(string $key): Encrypter
    {
        $cipher = (string) config('app.cipher', 'AES-256-CBC');

        if (str_starts_with($key, 'base64:')) {
            $key = base64_decode(substr($key, 7));
        }

        return new Encrypter($key, $cipher);
    }

    /**
     * Re-encrypt the credentials column of every Connection. Returns the number
     * of rows re-encrypted. The encrypted cast stores an unserialized inner
     * payload, so we decrypt/encrypt with `serialize: false` to move the exact
     * ciphertext payload across keys.
     */
    public function reencryptConnections(Encrypter $old, Encrypter $new): int
    {
        $count = 0;

        DB::table('connections')
            ->whereNotNull('credentials')
            ->orderBy('id')
            ->cursor()
            ->each(function (object $row) use ($old, $new, &$count): void {
                $payload = $old->decrypt($row->credentials, false);
                $reencrypted = $new->encrypt($payload, false);

                DB::table('connections')
                    ->where('id', $row->id)
                    ->update(['credentials' => $reencrypted]);

                $count++;
            });

        return $count;
    }
}
