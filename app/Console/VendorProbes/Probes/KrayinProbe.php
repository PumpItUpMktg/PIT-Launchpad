<?php

namespace App\Console\VendorProbes\Probes;

use App\Console\VendorProbes\ProbeResult;
use App\Console\VendorProbes\VendorProbe;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;
use Throwable;

/**
 * Krayin CRM (won-stage leads → conversions). Self-hosted, deferred until the
 * instance is stood up: READY when base URL + token are present and the instance
 * is reachable, SKIP otherwise.
 */
class KrayinProbe implements VendorProbe
{
    public function label(): string
    {
        return 'Krayin';
    }

    public function order(): int
    {
        return 80;
    }

    public function run(): ProbeResult
    {
        $baseUrl = (string) config('services.krayin.base_url');
        $token = (string) config('services.krayin.token');

        if ($baseUrl === '' || $token === '') {
            return ProbeResult::skip('KRAYIN_BASE_URL / KRAYIN_API_TOKEN not set (instance not deployed)');
        }

        try {
            $response = Http::withToken($token)->acceptJson()->timeout(10)
                ->get(rtrim($baseUrl, '/').'/api/v1/leads', ['limit' => 1]);

            return ProbeResult::ready("instance reachable (HTTP {$response->status()})");
        } catch (ConnectionException $e) {
            return ProbeResult::fail('Krayin unreachable — '.$e->getMessage());
        } catch (Throwable $e) {
            return ProbeResult::failFrom($e);
        }
    }
}
