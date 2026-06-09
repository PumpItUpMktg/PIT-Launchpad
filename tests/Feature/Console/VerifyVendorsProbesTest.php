<?php

use App\Console\VendorProbes\ProbeResult;
use App\Console\VendorProbes\ProbeStatus;
use App\Console\VendorProbes\VendorProbe;
use App\Console\VendorProbes\VendorProbeRegistry;
use Illuminate\Support\Facades\Http;

/**
 * The probe tests must never make a live call, even though the host environment
 * may carry real vendor credentials. Blank every credential so the keyed probes
 * take their deterministic SKIP path; GDELT (keyless) is exercised via Http::fake.
 */
beforeEach(function () {
    config([
        'services.anthropic.key' => '',
        'services.fal.key' => '',
        'services.dataforseo.login' => '',
        'services.dataforseo.password' => '',
        'filesystems.disks.r2.key' => '',
        'filesystems.disks.r2.bucket' => '',
        'services.news.provider' => 'gdelt',
    ]);
});

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
