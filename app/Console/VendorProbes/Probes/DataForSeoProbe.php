<?php

namespace App\Console\VendorProbes\Probes;

use App\Console\VendorProbes\ProbeResult;
use App\Console\VendorProbes\VendorProbe;
use App\Integrations\DataForSeo\DataForSeoClient;
use Illuminate\Http\Client\Factory as Http;
use Throwable;

/**
 * DataForSEO — the zero-cost account endpoint (appendix/user_data): confirms
 * Basic auth + connectivity without spending, reports balance.
 */
class DataForSeoProbe implements VendorProbe
{
    public function label(): string
    {
        return 'DataForSEO';
    }

    public function order(): int
    {
        return 40;
    }

    public function run(): ProbeResult
    {
        $login = (string) config('services.dataforseo.login');
        $password = (string) config('services.dataforseo.password');
        if ($login === '' || $password === '') {
            return ProbeResult::skip('DATAFORSEO_LOGIN / DATAFORSEO_PASSWORD not set');
        }

        try {
            $client = new DataForSeoClient(
                app(Http::class),
                $login,
                $password,
                (string) config('services.dataforseo.base_url', 'https://api.dataforseo.com'),
                (int) config('services.dataforseo.timeout', 30),
            );

            $data = $client->userData();
            $balance = $data['balance'] !== null ? '$'.number_format($data['balance'], 2) : 'n/a';

            return ProbeResult::live("appendix/user_data ok (login={$data['login']}, balance={$balance})");
        } catch (Throwable $e) {
            return ProbeResult::failFrom($e);
        }
    }
}
