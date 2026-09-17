<?php

use App\Enums\KeywordSource;
use App\Jobs\RunTownRankKeyword;
use App\Models\CoverageArea;
use App\Models\Keyword;
use App\Models\Location;
use App\Models\Site;
use App\Models\TownRankScan;
use App\TownRank\TownRankBoard;
use App\TownRank\TownRankKeywords;
use App\TownRank\TownRankScanner;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;

function trkSite(int $towns = 2): Site
{
    $site = Site::factory()->create(['brand_name' => 'SPG', 'domain_url' => 'https://spg.com']);
    $loc = Location::factory()->create(['site_id' => $site->id, 'lat' => 40.0, 'lng' => -74.0]);
    for ($i = 0; $i < $towns; $i++) {
        CoverageArea::factory()->create(['site_id' => $site->id, 'name' => "Town {$i}", 'population' => 100, 'lat' => 40.0 + $i / 100, 'lng' => -74.0, 'source_location_ids' => [$loc->id]]);
    }

    return $site;
}

it('tracks a keyword: reuses the site\'s existing keyword by exact query (case-insensitive), else creates it, and flags it', function () {
    $site = trkSite();
    $existing = Keyword::factory()->create(['site_id' => $site->id, 'query' => 'Sump Pump Service', 'track_town_rank' => false]);
    $other = Site::factory()->create();
    Keyword::factory()->create(['site_id' => $other->id, 'query' => 'sump pump repair']);   // another tenant's — never reused

    $reused = app(TownRankKeywords::class)->track($site, '  sump  pump service ');
    $created = app(TownRankKeywords::class)->track($site, 'sump pump repair');

    expect($reused->id)->toBe($existing->id)
        ->and($reused->fresh()->track_town_rank)->toBeTrue()
        ->and($created->site_id)->toBe((string) $site->id)
        ->and($created->query)->toBe('sump pump repair')
        ->and($created->source)->toBe(KeywordSource::Seed)
        ->and($created->status)->toBe('candidate')
        ->and($created->track_town_rank)->toBeTrue()
        ->and($created->is_grid_keyword)->toBeFalse();   // Town Rank never enlists a keyword in the Maps grid
    expect(fn () => app(TownRankKeywords::class)->track($site, '   '))->toThrow(InvalidArgumentException::class);
});

it('estimates a run as towns × modes not already collecting, queues it under the ceiling, and refuses over it or while collecting', function () {
    Queue::fake();
    $site = trkSite(2);
    $kw = app(TownRankKeywords::class)->track($site, 'sump pump repair');

    $e = app(TownRankKeywords::class)->estimate($site, $kw);
    expect($e['towns'])->toBe(2)->and($e['modes'])->toBe(['local', 'town_query'])->and($e['requests'])->toBe(4)->and($e['pending'])->toBeFalse();

    config()->set('launchpad.town_rank.request_ceiling', 3);
    $r = app(TownRankKeywords::class)->run($site, $kw);
    expect($r['queued'])->toBeFalse()->and($r['reason'])->toContain('exceeds the hard ceiling');
    Queue::assertNothingPushed();

    config()->set('launchpad.town_rank.request_ceiling', 100);
    $r = app(TownRankKeywords::class)->run($site, $kw);
    expect($r['queued'])->toBeTrue()->and($r['requests'])->toBe(4);
    Queue::assertPushed(RunTownRankKeyword::class, fn (RunTownRankKeyword $j): bool => $j->siteId === (string) $site->id && $j->keywordId === (string) $kw->id);

    // One mode still collecting → only the other is estimated; both collecting → refused.
    TownRankScan::create(['site_id' => $site->id, 'keyword_id' => $kw->id, 'mode' => 'local', 'status' => 'pending', 'scanned_at' => now()]);
    expect(app(TownRankKeywords::class)->estimate($site, $kw))->toMatchArray(['modes' => ['town_query'], 'requests' => 2, 'pending' => true]);
    TownRankScan::create(['site_id' => $site->id, 'keyword_id' => $kw->id, 'mode' => 'town_query', 'status' => 'pending', 'scanned_at' => now()]);
    $r = app(TownRankKeywords::class)->run($site, $kw);
    expect($r['queued'])->toBeFalse()->and($r['reason'])->toContain('already collecting');
});

it('the run job posts both modes and skips a mode whose latest scan is still collecting', function () {
    $ids = collect(['k-0', 'k-1']);
    Http::fake([
        '*/serp/google/organic/task_post' => Http::response(['status_code' => 20000, 'tasks' => $ids->map(fn ($id): array => ['id' => $id, 'status_code' => 20000])->all()]),
    ]);
    $site = trkSite(2);
    $kw = app(TownRankKeywords::class)->track($site, 'sump pump repair');
    TownRankScan::create(['site_id' => $site->id, 'keyword_id' => $kw->id, 'mode' => 'local', 'status' => 'pending', 'scanned_at' => now()]);

    (new RunTownRankKeyword((string) $site->id, (string) $kw->id))->handle(app(TownRankScanner::class));

    $scans = TownRankScan::query()->withoutGlobalScopes()->where('keyword_id', $kw->id)->get();
    expect($scans)->toHaveCount(2)   // the pre-existing pending local + one new town_query
        ->and($scans->where('mode', 'town_query')->count())->toBe(1)
        ->and($scans->where('mode', 'town_query')->first()->points()->count())->toBe(2);
    Http::assertSentCount(1);
});

it('the run job posts the other mode when one mode\'s post fails at the vendor', function () {
    $ids = collect(['k-0', 'k-1']);
    Http::fake([
        '*/serp/google/organic/task_post' => Http::sequence()
            ->push(['status_code' => 40000, 'status_message' => 'Bad request'])   // first mode (local): envelope error → exception
            ->push(['status_code' => 20000, 'tasks' => $ids->map(fn ($id): array => ['id' => $id, 'status_code' => 20000])->all()]),
    ]);
    $site = trkSite(2);
    $kw = app(TownRankKeywords::class)->track($site, 'sump pump repair');

    (new RunTownRankKeyword((string) $site->id, (string) $kw->id))->handle(app(TownRankScanner::class));

    $scans = TownRankScan::query()->withoutGlobalScopes()->where('keyword_id', $kw->id)->get();
    expect($scans)->toHaveCount(1)
        ->and($scans->first()->mode)->toBe('town_query')   // local failed and was logged; town_query still went out
        ->and($scans->first()->status)->toBe('pending');
});

it('removes a keyword from the wall: both flags off, every collected scan kept', function () {
    $site = trkSite();
    $keyword = Keyword::factory()->create(['site_id' => $site->id, 'query' => 'sump pump service',
        'track_town_rank' => true, 'is_grid_keyword' => true]);
    TownRankScan::create(['site_id' => $site->id, 'keyword_id' => $keyword->id, 'mode' => 'town_query',
        'status' => 'complete', 'points_count' => 2, 'found_count' => 1, 'scanned_at' => now()]);

    $was = app(TownRankKeywords::class)->untrack($site, $keyword);

    expect($was)->toBe(['tracked' => true, 'grid' => true, 'scans' => 1])
        ->and($keyword->fresh()->track_town_rank)->toBeFalse()
        ->and($keyword->fresh()->is_grid_keyword)->toBeFalse()
        // Paid-for data is never deleted by a removal — re-adding the keyword brings the board back.
        ->and(TownRankScan::withoutGlobalScopes()->where('keyword_id', $keyword->id)->count())->toBe(1);

    // And it is gone from the wall, scans or not.
    expect(collect(app(TownRankBoard::class)->keywords($site))->pluck('keyword_id'))
        ->not->toContain((string) $keyword->id);

    // Adding it back restores the card with its history.
    app(TownRankKeywords::class)->track($site, 'sump pump service');
    expect(collect(app(TownRankBoard::class)->keywords($site))->firstWhere('keyword_id', (string) $keyword->id))
        ->not->toBeNull();
});

it('orders the wall by the operator priority, scanned-first within a tie', function () {
    $site = trkSite();
    $plain = Keyword::factory()->create(['site_id' => $site->id, 'query' => 'aaa first alphabetically', 'track_town_rank' => true, 'priority' => 0]);
    $important = Keyword::factory()->create(['site_id' => $site->id, 'query' => 'zzz last alphabetically', 'track_town_rank' => true, 'priority' => 3]);

    $order = collect(app(TownRankBoard::class)->keywords($site))->pluck('keyword_id')->all();

    expect($order)->toBe([(string) $important->id, (string) $plain->id]);
});
