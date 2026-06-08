<?php

namespace App\Console\Commands;

use App\Security\AppKeyRotator;
use Illuminate\Console\Command;
use Illuminate\Encryption\Encrypter;

/**
 * Re-encrypts every Connection secret from the current APP_KEY to a new one.
 * This performs the data mechanics (decrypt-old → re-encrypt-new → save); the
 * operator then sets the printed key as APP_KEY (moving the old to
 * APP_PREVIOUS_KEYS during the transition, then dropping it). The new key is the
 * only sensitive value printed, and only to the operator running the command.
 */
class RotateAppKeyCommand extends Command
{
    protected $signature = 'launchpad:rotate-app-key
        {--new-key= : The new APP_KEY (base64:...); generated if omitted}';

    protected $description = 'Re-encrypt all Connection secrets under a new APP_KEY.';

    public function handle(AppKeyRotator $rotator): int
    {
        $oldKey = (string) config('app.key');
        if ($oldKey === '') {
            $this->error('No current APP_KEY configured.');

            return self::FAILURE;
        }

        $cipher = (string) config('app.cipher', 'AES-256-CBC');
        $newKey = (string) ($this->option('new-key') ?: 'base64:'.base64_encode(Encrypter::generateKey($cipher)));

        $old = $rotator->encrypterForKey($oldKey);
        $new = $rotator->encrypterForKey($newKey);

        $count = $rotator->reencryptConnections($old, $new);

        $this->info("Re-encrypted {$count} connection(s) under the new key.");
        $this->line('Set this as APP_KEY (keep the previous key in APP_PREVIOUS_KEYS until the swap is verified, then drop it):');
        $this->line($newKey);

        return self::SUCCESS;
    }
}
