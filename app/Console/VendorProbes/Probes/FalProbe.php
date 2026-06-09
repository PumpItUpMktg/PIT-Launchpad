<?php

namespace App\Console\VendorProbes\Probes;

use App\Console\VendorProbes\ProbeResult;
use App\Console\VendorProbes\VendorProbe;
use App\Integrations\Fal\FalClient;
use Throwable;

/**
 * fal — one cheapest-possible image (512x512). One image maximum.
 */
class FalProbe implements VendorProbe
{
    public function label(): string
    {
        return 'fal';
    }

    public function order(): int
    {
        return 20;
    }

    public function run(): ProbeResult
    {
        if ((string) config('services.fal.key') === '') {
            return ProbeResult::skip('FAL_KEY not set');
        }

        try {
            $image = app(FalClient::class)->generate(
                'a plain light gray test square, minimal, flat color',
                ['width' => 512, 'height' => 512],
            );

            return ProbeResult::live("image returned ({$image->width}x{$image->height}, ".strlen($image->bytes).' bytes)');
        } catch (Throwable $e) {
            return ProbeResult::failFrom($e);
        }
    }
}
