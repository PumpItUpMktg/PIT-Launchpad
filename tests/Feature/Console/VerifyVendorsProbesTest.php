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
        ->toBe(['Claude', 'fal', 'R2', 'DataForSEO', 'News', 'Embeddings', 'Google', 'Krayin', 'Mautic']);
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
        ->and($byLabel['DataForSEO']->run()->status)->toBe(ProbeStatus::Skip)
        ->and($byLabel['Embeddings']->run()->status)->toBe(ProbeStatus::Skip)
        ->and($byLabel['Google']->run()->status)->toBe(ProbeStatus::Skip)
        ->and($byLabel['Krayin']->run()->status)->toBe(ProbeStatus::Skip)
        ->and($byLabel['Mautic']->run()->status)->toBe(ProbeStatus::Skip);

    Http::assertNothingSent();
});

it('runs the configured news probe live (faked) and asserts XML body-shape', function () {
    // Default provider is Google News — the probe asserts XML + a parsed item.
    Http::fake([
        '*/rss/search*' => Http::response(
            '<?xml version="1.0"?><rss version="2.0"><channel><item>'
            .'<title>A title</title><link>https://www.google.com/url?url=https://tribune.com/story</link>'
            .'<pubDate>Mon, 01 Jun 2026 14:30:00 GMT</pubDate><source url="https://tribune.com">Tribune</source>'
            .'</item></channel></rss>',
            200,
            ['Content-Type' => 'application/xml'],
        ),
    ]);

    $news = collect(app(VendorProbeRegistry::class)->all())
        ->firstOrFail(fn (VendorProbe $p) => $p->label() === 'News');

    $result = $news->run();

    expect($result->status)->toBe(ProbeStatus::Live)
        ->and($result->detail)->toContain('provider=googlenews')
        ->and($result->detail)->toContain('xml');
});

it('the news probe FAILs on a consent HTML page (no false LIVE)', function () {
    Http::fake([
        '*/rss/search*' => Http::response('<!doctype html><html><title>Before you continue</title></html>', 200, ['Content-Type' => 'text/html']),
    ]);

    $news = collect(app(VendorProbeRegistry::class)->all())
        ->firstOrFail(fn (VendorProbe $p) => $p->label() === 'News');

    $result = $news->run();

    expect($result->status)->toBe(ProbeStatus::Fail)
        ->and($result->detail)->toContain('consent');
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
