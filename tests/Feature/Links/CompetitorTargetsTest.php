<?php

use App\KeywordGenerator\Beatability\CompetitorClassifier;
use App\Links\CompetitorTargets;
use App\Models\Keyword;
use App\Models\Site;
use App\Models\TownRankPoint;
use App\Models\TownRankScan;
use Illuminate\Support\Carbon;

function ctScan(Site $site, Keyword $kw, string $mode, Carbon $at, array $points): void
{
    $scan = TownRankScan::create(['site_id' => $site->id, 'keyword_id' => $kw->id, 'mode' => $mode, 'status' => 'complete', 'points_count' => count($points), 'found_count' => 0, 'scanned_at' => $at]);
    foreach ($points as $i => [$rank, $top]) {
        TownRankPoint::create(['site_id' => $site->id, 'scan_id' => $scan->id, 'label' => "Town {$i}", 'lat' => 40.0 + $i, 'lng' => -74.0, 'query' => $kw->query, 'rank' => $rank, 'collected_at' => $at,
            'top_results' => array_map(fn (array $r): array => ['position' => $r[0], 'url' => "https://{$r[1]}/p", 'domain' => $r[1]], $top)]);
    }
}

it('ranks the local competitors found above us across priority keywords, dropping aggregators, chains, us, and anyone below us', function () {
    $site = Site::factory()->create(['domain_url' => 'https://www.sumppumpgurus.com']);
    $kw = Keyword::factory()->create(['site_id' => $site->id, 'query' => 'sump pump installation', 'town_priority_rank' => 1]);
    $old = Carbon::parse('2026-09-01');
    $new = Carbon::parse('2026-10-01');
    // An older scan whose competitor must NOT count (only the latest scan per keyword/mode is read).
    ctScan($site, $kw, 'town_query', $old, [[1, [[1, 'stale-rival.com']]]]);
    ctScan($site, $kw, 'town_query', $new, [
        [3, [[1, 'www.rivalplumbing.com'], [2, 'yelp.com'], [3, 'sumppumpgurus.com'], [4, 'belowus.com']]],
        [null, [[1, 'rivalplumbing.com'], [2, 'dryguys.com'], [3, 'homedepot.com']]],
    ]);
    ctScan($site, $kw, 'local', $new, [[2, [[1, 'dryguys.com'], [2, 'sumppumpgurus.com']]]]);

    $targets = app(CompetitorTargets::class)->for($site, 10);

    expect($targets)->toBe([
        ['domain' => 'dryguys.com', 'appearances' => 2],     // ties break by name, so the order is stable
        ['domain' => 'rivalplumbing.com', 'appearances' => 2],
    ]);
});

it('falls back to every scanned keyword when none is a priority, and caps the list', function () {
    $site = Site::factory()->create(['domain_url' => 'https://sumppumpgurus.com']);
    $kw = Keyword::factory()->create(['site_id' => $site->id, 'query' => 'radon mitigation', 'town_priority_rank' => null]);
    ctScan($site, $kw, 'town_query', Carbon::parse('2026-10-01'), [[null, [[1, 'a.com'], [2, 'b.com'], [3, 'c.com']]]]);

    expect(array_column(app(CompetitorTargets::class)->for($site, 2), 'domain'))->toBe(['a.com', 'b.com']);
});

it('never treats a platform host as a competitor — a Facebook page or a Google Business site above us is not a target', function () {
    $site = Site::factory()->create(['domain_url' => 'https://sumppumpgurus.com']);
    $kw = Keyword::factory()->create(['site_id' => $site->id, 'query' => 'sump pump repair', 'town_priority_rank' => 1]);
    ctScan($site, $kw, 'town_query', Carbon::parse('2026-10-01'), [[null, [[1, 'www.facebook.com'], [2, 'rival-pumps.business.site'], [3, 'localrival.com'], [4, 'smith-plumbing.wixsite.com']]]]);

    expect(array_column(app(CompetitorTargets::class)->for($site, 10), 'domain'))->toBe(['localrival.com']);
});

it('reads the registrable domain, drops foreign country-code sites, forums and franchises — what Sump Pump Gurus\' first run let through', function () {
    $site = Site::factory()->create(['domain_url' => 'https://sumppumpgurus.com']);
    $kw = Keyword::factory()->create(['site_id' => $site->id, 'query' => 'sump pump installation', 'town_priority_rank' => 1]);
    ctScan($site, $kw, 'town_query', Carbon::parse('2026-10-01'), [[null, [
        [1, 'doityourself.com'], [2, 'm.yelp.com'], [3, 'harrisplumbing.ca'], [4, 'ca.jackery.com'], [5, 'mrhandyman.com'],
        [6, 'www.oriolebw.com'], [7, 'blog.oriolebw.com'], [8, 'patriotpumpservice.com'],
    ]]]);

    expect(app(CompetitorTargets::class)->for($site, 10))->toBe([
        ['domain' => 'oriolebw.com', 'appearances' => 2],   // www. and blog. are one business
        ['domain' => 'patriotpumpservice.com', 'appearances' => 1],
    ]);
});

it('the registrable domain keeps a two-level public suffix', function () {
    expect(CompetitorClassifier::registrable('m.yelp.com'))->toBe('yelp.com')
        ->and(CompetitorClassifier::registrable('shop.example.co.uk'))->toBe('example.co.uk')
        ->and(CompetitorClassifier::registrable('rival-pumps.business.site'))->toBe('business.site');
});
