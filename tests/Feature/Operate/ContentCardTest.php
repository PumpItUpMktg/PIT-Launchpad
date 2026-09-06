<?php

use App\Operate\ContentCard;
use Illuminate\Support\Facades\Blade;

/** A minimal valid card; override fields per test (keys are ContentCard constructor param names). */
function card(array $overrides = []): array
{
    $args = array_merge([
        'id' => '01ID', 'title' => 'Basement Waterproofing', 'url' => 'https://x/bw', 'type' => 'service', 'typeLabel' => 'Service',
        'locked' => false, 'indexed' => true, 'indexState' => 'indexed', 'indexLabel' => 'Indexed',
        'rank' => 4, 'delta' => 2, 'impressions' => 120, 'clicks' => 9, 'sessions' => 30, 'keyword' => 'basement waterproofing', 'pending' => false,
    ], $overrides);

    return (new ContentCard(...$args))->toArray();
}

it('the DTO always carries the index verdict — omission is impossible', function () {
    // Every core field is a required constructor arg, so a producer cannot build a card without the index
    // verdict (the field whose absence rendered no chip). toArray always exposes it, flat and nested.
    $row = card();
    expect($row['index_label'])->toBe('Indexed')
        ->and($row['index_state'])->toBe('indexed')
        ->and($row['metrics']['index']['state'])->toBe('indexed');
});

it('resolveIndex is the one three-state implementation (durable OR gsc, else unchecked)', function () {
    expect(ContentCard::resolveIndex(true, true, false))->toBe([true, 'indexed', 'Indexed'])       // PASS row
        ->and(ContentCard::resolveIndex(false, false, true))->toBe([true, 'indexed', 'Indexed'])   // GSC in-google
        ->and(ContentCard::resolveIndex(false, true, false))->toBe([false, 'not_indexed', 'Not indexed']) // inspected, not PASS
        ->and(ContentCard::resolveIndex(false, false, false))->toBe([false, 'unchecked', 'Not yet checked']); // never inspected
});

it('renders the rich blocks when the row carries them (the Pages shape)', function () {
    $html = Blade::render('<x-lp.content-card :row="$row" />', ['row' => card([
        'daysLive' => 12,
        'indexnowAt' => '2026-09-01',
        'localRank' => 3, 'localMarket' => 'Fallston MD',
        'series' => [['captured_at' => '2026-08-01', 'rank' => 8], ['captured_at' => '2026-09-01', 'rank' => 4]],
        'refreshCount' => 2,
        'queries' => [['query' => 'basement waterproofing fallston', 'impressions' => 40, 'clicks' => 5, 'ctr' => 12.5, 'position' => 4.1]],
    ])]);

    expect($html)->toContain('Indexed')                          // the chip always
        ->toContain('Live · 12d')                                // days-live
        ->toContain('Submitted to Bing')                         // IndexNow pill
        ->toContain('Local pack #3')                             // local-pack
        ->toContain('<polyline')                                 // sparkline
        ->toContain('Found in search for')                       // GSC query terms
        ->toContain('basement waterproofing fallston');
});

it('omits every rich block when the row does not carry them (the Live shape) — one component, no divergence', function () {
    $html = Blade::render('<x-lp.content-card :row="$row" />', ['row' => card()]); // lean: no series/queries/local/indexnow/days

    expect($html)->toContain('Indexed')                          // the shared chip still renders
        ->not->toContain('Live · ')                              // no days-live
        ->not->toContain('Submitted to Bing')                    // no IndexNow
        ->not->toContain('Local pack')                           // no local-pack
        ->not->toContain('<polyline')                            // no sparkline
        ->not->toContain('Found in search for');                 // no query terms
});

it('a warmed-but-empty sessions cell reads "No traffic yet", not a bare dash (preserves the GA4 fix)', function () {
    $html = Blade::render('<x-lp.content-card :row="$row" />', ['row' => card([
        'sessions' => null, 'trafficPending' => 'No traffic yet',
    ])]);

    expect($html)->toContain('No traffic yet');
});
