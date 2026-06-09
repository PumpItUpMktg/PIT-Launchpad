<?php

namespace App\Console\VendorProbes\Probes;

use App\Console\VendorProbes\ProbeResult;
use App\Console\VendorProbes\VendorProbe;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Throwable;

/**
 * R2 — put a tiny object, read it back, delete it.
 */
class R2Probe implements VendorProbe
{
    public function label(): string
    {
        return 'R2';
    }

    public function order(): int
    {
        return 30;
    }

    public function run(): ProbeResult
    {
        if ((string) config('filesystems.disks.r2.key') === '' || (string) config('filesystems.disks.r2.bucket') === '') {
            return ProbeResult::skip('R2_ACCESS_KEY_ID / R2_BUCKET not set');
        }

        $object = 'verify-vendors/ping-'.Str::ulid().'.txt';
        $payload = 'launchpad-verify-vendors';

        try {
            $disk = Storage::disk('r2');
            $disk->put($object, $payload);
            $readback = $disk->get($object);

            $publicNote = (string) config('services.r2.public_url') !== ''
                ? ' (public URL: '.$disk->url($object).')'
                : ' (R2_PUBLIC_URL not set)';

            $disk->delete($object);

            return $readback === $payload
                ? ProbeResult::live('put/get/delete ok'.$publicNote)
                : ProbeResult::fail('readback mismatch');
        } catch (Throwable $e) {
            return ProbeResult::failFrom($e);
        }
    }
}
