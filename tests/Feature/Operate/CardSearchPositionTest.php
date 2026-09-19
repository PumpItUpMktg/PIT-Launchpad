<?php

use App\Operate\ContentCard;
use Illuminate\Support\Facades\Blade;

/**
 * A bare "—" in the Rank cell reads as a rank of zero. LiveMetrics already computes a four-state
 * vocabulary for exactly this — ranked / tracked_not_ranking / checking / not_tracked, with a comment
 * saying "never a fake dash" — and the card was throwing that label away. It shows it now.
 */
it('shows why there is no rank instead of a bare dash', function () {
    $row = (new ContentCard(
        id: '01ABC', title: 'Sump Pump Repair', url: 'https://spg.example/sump-pump-repair/',
        type: 'service', typeLabel: 'Service', locked: false,
        indexed: true, indexState: 'indexed', indexLabel: 'Indexed',
        rank: null, delta: null, impressions: null, clicks: null, sessions: null,
        keyword: 'sump pump repair', pending: false, positionPending: 'Pending first snapshot',
    ))->toArray();

    expect($row['rank'])->toBeNull()
        ->and($row['position_pending'])->toBe('Pending first snapshot');

    $html = Blade::render('<x-lp.content-card :row="$row" />', ['row' => $row]);
    expect($html)->toContain('Pending first snapshot')
        ->and($html)->not->toContain('Rank <b>—</b>');
});

/**
 * Search position is Google's own blended rank — the average across every query the page was seen for,
 * weighted by impressions. It is a different measure from a tracked rank for one keyword, so it carries
 * its own name rather than being quietly substituted into the Rank cell.
 */
it('shows the Google blended position beside the tracked rank, named apart', function () {
    $row = (new ContentCard(
        id: '01ABC', title: 'Grinder & Sewage Pump Service', url: 'https://spg.example/grinder/',
        type: 'service', typeLabel: 'Service', locked: false,
        indexed: true, indexState: 'indexed', indexLabel: 'Indexed',
        rank: null, delta: null, impressions: 2113, clicks: 1, sessions: null,
        keyword: 'grinder pump service', pending: false,
        ctr: 0.00047, searchPosition: 18.4, positionPending: 'Pending first snapshot',
    ))->toArray();

    $html = Blade::render('<x-lp.content-card :row="$row" />', ['row' => $row]);

    expect($html)->toContain('Search position')
        ->and($html)->toContain('18.4')
        ->and($html)->toContain('CTR')
        // The two measures stay distinct: the blended number never fills the tracked-rank cell.
        ->and($html)->toContain('Pending first snapshot');
});

/**
 * The per-query chips were already there — query plus its own position, six of them. This asserts they
 * stay, because the blended "Search position" above is an average across exactly these, and the average
 * alone would hide that one query sits at 21 and another at 33.
 */
it('keeps the per-query positions beside the blended average', function () {
    $row = (new ContentCard(
        id: '01ABC', title: 'Sump Pump Repair', url: 'https://spg.example/sump-pump-repair/',
        type: 'service', typeLabel: 'Service', locked: false,
        indexed: true, indexState: 'indexed', indexLabel: 'Indexed',
        rank: null, delta: null, impressions: 803, clicks: 0, sessions: null,
        keyword: 'sump pump repair', pending: false, searchPosition: 24.1,
        queries: [
            ['query' => 'sump pump repair cost', 'clicks' => 0, 'impressions' => 640, 'ctr' => 0.0, 'position' => 21.5],
            ['query' => 'sump pump repair near me', 'clicks' => 0, 'impressions' => 163, 'ctr' => 0.0, 'position' => 33.2],
        ],
    ))->toArray();

    $html = Blade::render('<x-lp.content-card :row="$row" />', ['row' => $row]);

    expect($html)->toContain('Search position')
        ->and($html)->toContain('24.1')
        ->and($html)->toContain('sump pump repair cost')
        ->and($html)->toContain('21.5')
        ->and($html)->toContain('33.2');
});

/** No GSC position yet (a new page) shows no Search position cell rather than a fabricated zero. */
it('omits the search position entirely when Google has not reported one', function () {
    $row = (new ContentCard(
        id: '01ABC', title: 'Yard Drainage Solutions', url: 'https://spg.example/yard-drainage/',
        type: 'service', typeLabel: 'Service', locked: false,
        indexed: true, indexState: 'indexed', indexLabel: 'Indexed',
        rank: null, delta: null, impressions: null, clicks: null, sessions: null,
        keyword: 'yard drainage', pending: false,
        gscPending: 'Collecting — first data in a few days',
    ))->toArray();

    $html = Blade::render('<x-lp.content-card :row="$row" />', ['row' => $row]);

    expect($html)->not->toContain('Search position')
        ->and($html)->toContain('Collecting — first data in a few days');
});
