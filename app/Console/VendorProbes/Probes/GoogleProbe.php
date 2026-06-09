<?php

namespace App\Console\VendorProbes\Probes;

use App\Console\VendorProbes\ProbeResult;
use App\Console\VendorProbes\VendorProbe;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;
use Throwable;

/**
 * Google (GSC + GA4) — an OAuth vendor, so the platform probe can't fetch data
 * (that needs a per-tenant token). It verifies platform OAuth readiness: client
 * id/secret/redirect configured and the token endpoint reachable, and reports
 * READY. The real per-tenant live check (one GSC + one GA4 row) happens at
 * connect time, in the connect flow.
 */
class GoogleProbe implements VendorProbe
{
    public function label(): string
    {
        return 'Google';
    }

    public function order(): int
    {
        return 70;
    }

    public function run(): ProbeResult
    {
        $missing = array_keys(array_filter([
            'GOOGLE_CLIENT_ID' => (string) config('services.google.client_id') === '',
            'GOOGLE_CLIENT_SECRET' => (string) config('services.google.client_secret') === '',
            'GOOGLE_REDIRECT_URI' => (string) config('services.google.redirect_uri') === '',
        ]));

        if ($missing !== []) {
            return ProbeResult::skip(implode(' / ', $missing).' not set');
        }

        try {
            // Any HTTP response (even a 400 invalid_request) proves the token
            // endpoint is reachable — no credentials are spent.
            $response = Http::asForm()->timeout(10)->post((string) config('services.google.token_uri'), []);

            return ProbeResult::ready(
                "platform OAuth configured; token endpoint reachable (HTTP {$response->status()}); per-tenant tokens connect at runtime",
            );
        } catch (ConnectionException $e) {
            return ProbeResult::fail('token endpoint unreachable — '.$e->getMessage());
        } catch (Throwable $e) {
            return ProbeResult::failFrom($e);
        }
    }
}
