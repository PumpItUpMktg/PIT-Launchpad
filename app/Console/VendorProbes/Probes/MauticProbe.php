<?php

namespace App\Console\VendorProbes\Probes;

use App\Console\VendorProbes\ProbeResult;
use App\Console\VendorProbes\VendorProbe;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;
use Throwable;

/**
 * Mautic (form submissions / campaign goals → conversions). Self-hosted, deferred
 * until the instance is stood up: READY when base URL + OAuth creds are present
 * and the token endpoint is reachable, SKIP otherwise.
 */
class MauticProbe implements VendorProbe
{
    public function label(): string
    {
        return 'Mautic';
    }

    public function order(): int
    {
        return 90;
    }

    public function run(): ProbeResult
    {
        $baseUrl = (string) config('services.mautic.base_url');
        $clientId = (string) config('services.mautic.client_id');
        $clientSecret = (string) config('services.mautic.client_secret');

        if ($baseUrl === '' || $clientId === '' || $clientSecret === '') {
            return ProbeResult::skip('MAUTIC_BASE_URL / MAUTIC_CLIENT_ID / MAUTIC_CLIENT_SECRET not set (instance not deployed)');
        }

        try {
            // Any HTTP response from the token endpoint proves reachability; no
            // valid grant is required to confirm the instance is up.
            $response = Http::asForm()->timeout(10)
                ->post(rtrim($baseUrl, '/').'/oauth/v2/token', ['grant_type' => 'client_credentials']);

            return ProbeResult::ready("token endpoint reachable (HTTP {$response->status()})");
        } catch (ConnectionException $e) {
            return ProbeResult::fail('Mautic unreachable — '.$e->getMessage());
        } catch (Throwable $e) {
            return ProbeResult::failFrom($e);
        }
    }
}
