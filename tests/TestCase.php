<?php

namespace Tests;

use App\Integrations\Census\Geocoder;
use App\Integrations\Census\GeocodeResult;
use Illuminate\Foundation\Testing\TestCase as BaseTestCase;

abstract class TestCase extends BaseTestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        // No test ever geocodes over the network. Address-bearing fixtures otherwise trigger
        // the sync-queued GeocodeLocation on workspace mount, and a LIVE geocoder hit that
        // happens to succeed resolves a home county → rebuilds coverage rows out from under
        // the test (the CI-only flake this pins down). Null = the deterministic
        // geocode_failed path; tests that need a located base set lat/lng on the fixture.
        $this->app->instance(Geocoder::class, new class implements Geocoder
        {
            public function geocode(string $address): ?GeocodeResult
            {
                return null;
            }
        });
    }
}
