<?php

use App\Console\VendorProbes\ProbeResult;
use App\Console\VendorProbes\ProbeStatus;
use App\Console\VendorProbes\VendorProbe;
use App\Console\VendorProbes\VendorProbeRegistry;
use Illuminate\Support\Facades\Http;

/**
 * No credentials are blanked here on purpose: phpunit.xml force-blanks every
 * vendor secret globally, so the keyed probes must take their deterministic SKIP
 * path with no local setup. This file doubles as the canary for that floor — if
 * a real credential ever leaks into the test env, the SKIP test below goes red.
 * GDELT (keyless) is exercised via Http::fake.
 */
it('auto-discovers every vendor probe in deterministic order', function () {
    $probes = app(VendorProbeRegistry::class)->all();

    foreach ($probes as $probe) {
        expect($probe)->toBeInstanceOf(VendorProbe::class);
    }

    expect(array_map(fn (VendorProbe $p) => $p->label(), $probes))
        ->toBe(['Claude', 'fal', 'R2', 'DataForSEO', 'News']);
});

it('skips keyless vendors without making any outbound call', function () {
    Http::fake(); // any accidental call would be recorded

    $byLabel = [];
    foreach (app(VendorProbeRegistry::class)->all() as $probe) {
        $byLabel[$probe->label()] = $probe;
    }

    expect($byLabel['Claude']->run()->status)->toBe(ProbeStatus::Skip)
        ->and($byLabel['fal']->run()->status)->toBe(ProbeStatus::Skip)
        ->and($byLabel['R2']->run()->status)->toBe(ProbeStatus::Skip)
        ->and($byLabel['DataForSEO']->run()->status)->toBe(ProbeStatus::Skip);

    Http::assertNothingSent();
});

it('runs the configured news probe live (faked) and reports the count', function () {
    Http::fake([
        '*' => Http::response(['articles' => [[
            'url' => 'https://tribune.com/story',
            'title' => 'A title',
            'seendate' => '20260605T143000Z',
            'domain' => 'tribune.com',
        ]]]),
    ]);

    $news = collect(app(VendorProbeRegistry::class)->all())
        ->firstOrFail(fn (VendorProbe $p) => $p->label() === 'News');

    $result = $news->run();

    expect($result->status)->toBe(ProbeStatus::Live)
        ->and($result->detail)->toContain('provider=gdelt')
        ->and($result->detail)->toContain('1 item');
});

it('formats a result as an aligned LIVE/SKIP/FAIL line', function () {
    $line = ProbeResult::live('all good')->line('News', 10);

    expect($line)->toStartWith('News ')
        ->and($line)->toContain(' : LIVE — all good');
});

it('the verify-vendors command runs all probes and prints a summary', function () {
    // All keyed probes SKIP; News (GDELT, keyless) is faked — no live call.
    Http::fake(['*' => Http::response(['articles' => []])]);

    $this->artisan('launchpad:verify-vendors')
        ->assertSuccessful()
        ->expectsOutputToContain('Claude')
        ->expectsOutputToContain('DataForSEO')
        ->expectsOutputToContain('News')
        ->expectsOutputToContain('LIVE/FAIL summary');
});
