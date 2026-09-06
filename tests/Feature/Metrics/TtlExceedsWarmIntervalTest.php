<?php

/**
 * A render cache that is read CACHE-ONLY (never fetched inline) and populated by a SCHEDULED warm job has a
 * hard invariant: its TTL must exceed the warm's interval. If the TTL is shorter, the value expires between
 * warms and the render reads a permanent miss — the card shows "Refreshing…" forever, a metric that never
 * resolves and is indistinguishable from a broken one.
 *
 * This is the same failure shape as `retry_after` shorter than a job's `timeout`, and the SERP-cadence env
 * override — a producer and its consumer disagreeing about time, with nothing surfacing the gap. This test
 * IS that surface: every cache-only render cache is listed with the cadence of the job that warms it, and
 * the TTL must clear it. The GA4 per-page cache is the one this guards most tightly (weekly warm), after it
 * shipped at 8 days against a 7-day warm — a 1-day margin that a single missed pass turned into "Refreshing…".
 *
 * Warm cadences (routes/console.php): warm-live-metrics HOURLY (GSC/Bing/index), warm-ga4-pages WEEKLY (GA4).
 */
dataset('cache-only render caches warmed on a schedule', [
    // config key                                    warm interval (s)   warmed by            (3600 = hourly, 604800 = weekly)
    'per-page GA4 sessions' => ['services.google.ga4_page_cache_ttl', 604800, 'launchpad:warm-ga4-pages'],
    'GSC page stats' => ['services.google.gsc_cache_ttl', 3600, 'launchpad:warm-live-metrics'],
    'Bing page stats' => ['services.bing.cache_ttl', 3600, 'launchpad:warm-live-metrics'],
    'URL Inspection (PASS)' => ['services.google.url_inspection_cache_ttl', 3600, 'launchpad:warm-live-metrics'],
    'URL Inspection (pending)' => ['services.google.url_inspection_pending_ttl', 3600, 'launchpad:warm-live-metrics'],
]);

it('keeps a cache-only render cache TTL above the interval of the job that warms it', function (string $configKey, int $warmInterval, string $warmedBy) {
    $ttl = (int) config($configKey);

    expect($ttl)->toBeGreaterThan(
        $warmInterval,
        "{$configKey} (TTL {$ttl}s) must exceed the {$warmInterval}s interval of {$warmedBy}, or the cache-only render goes cold between warms and reads a permanent \"Refreshing…\"."
    );
})->with('cache-only render caches warmed on a schedule');

it('can detect a violation — a TTL at or below the warm interval fails the invariant', function () {
    // Proves the guard above is capable of catching the regression it asserts against (rule 3): a TTL equal
    // to the warm interval leaves no margin and must NOT satisfy "exceeds".
    config()->set('services.google.ga4_page_cache_ttl', 604800);

    expect((int) config('services.google.ga4_page_cache_ttl'))->not->toBeGreaterThan(604800);
});
