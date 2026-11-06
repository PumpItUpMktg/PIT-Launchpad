<?php

use App\Analytics\Gsc\Grain;
use App\ContentEngine\Questions\BotQueryGuard;
use App\ContentEngine\Questions\QuestionMiner;
use App\Models\Site;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

function questionGscRow(Site $site, string $date, string $query, int $impressions, int $clicks, ?float $position, string $url = 'https://x.test/p/'): void
{
    DB::table('gsc_url_query_daily')->insert([
        'id' => (string) Str::ulid(),
        'site_id' => $site->id,
        'grain_hash' => Grain::hash([$site->id, $date, $url, $query, 'usa', 'MOBILE']),
        'date' => $date,
        'url' => $url,
        'query' => $query,
        'country' => 'usa',
        'device' => 'MOBILE',
        'impressions' => $impressions,
        'clicks' => $clicks,
        'ctr' => $impressions > 0 ? $clicks / $impressions : 0,
        'position' => $position,
        'created_at' => now(),
        'updated_at' => now(),
    ]);
}

it('aggregates the window per query with GSC-weighted position, keeps questions, drops keywords and bots, and attaches the ranking URLs', function () {
    $site = Site::factory()->create(['brand_name' => 'Acme Pumps', 'domain_url' => 'https://acmepumps.test']);
    $asOf = CarbonImmutable::parse('2026-11-01');

    // A real question over two days on two URLs.
    questionGscRow($site, '2026-10-01', 'how long do sump pumps last', 60, 2, 10.0, 'https://acmepumps.test/blog/life/');
    questionGscRow($site, '2026-10-20', 'how long do sump pumps last', 40, 1, 20.0, 'https://acmepumps.test/blog/life/');
    questionGscRow($site, '2026-10-20', 'how long do sump pumps last', 10, 0, 30.0, 'https://acmepumps.test/');
    // Its sibling phrasing clusters in.
    questionGscRow($site, '2026-10-05', 'how long does a sump pump last', 20, 1, 15.0, 'https://acmepumps.test/blog/life/');
    // A keyword, not a question.
    questionGscRow($site, '2026-10-05', 'sump pump repair', 500, 30, 3.0);
    // A brand query.
    questionGscRow($site, '2026-10-05', 'is acme pumps any good', 30, 10, 1.0);
    // Machine traffic: appeared this week at volume, never clicked.
    questionGscRow($site, '2026-10-29', 'how to organize sump pump', 1300, 0, 7.0);
    // Outside the window.
    questionGscRow($site, '2026-06-01', 'why is my sump pump so loud', 80, 3, 5.0);
    // Another tenant.
    $other = Site::factory()->create();
    questionGscRow($other, '2026-10-05', 'why does my sump pump smell', 90, 4, 4.0);

    $report = (new QuestionMiner(new BotQueryGuard))->mine($site, 90, $asOf);

    expect($report->queriesRead)->toBe(5)
        ->and($report->questionShaped)->toBe(3)        // two phrasings + the bot query (question-shaped; the guard drops it next)
        ->and(count($report->botDropped))->toBe(1)
        ->and($report->botDropped[0]['query'])->toBe('how to organize sump pump')
        ->and($report->botDropped[0]['reason'])->toBe('zero_click')
        ->and($report->clusters)->toHaveCount(1);

    $c = $report->clusters[0];
    expect($c->query())->toBe('how long do sump pumps last')
        ->and($c->otherPhrasings())->toBe(['how long does a sump pump last'])
        ->and($c->impressions())->toBe(130)
        ->and($c->clicks())->toBe(4)
        ->and($c->lead->position)->toBe(15.5)             // (10×60 + 20×40 + 30×10) / 110
        ->and($c->lead->bestPosition)->toBe(10.0)
        ->and($c->lead->daysSeen)->toBe(2)
        ->and($c->lead->firstSeen)->toBe('2026-10-01')
        ->and($c->lead->peakDaily)->toBe(60)
        ->and($c->topUrl())->toBe('https://acmepumps.test/blog/life/')
        ->and($c->topUrlPosition())->toBe(14.2);           // (10×60 + 20×40 + 15×20) / 120 = 14.17
});

it('reports an empty store honestly', function () {
    $site = Site::factory()->create();

    $report = app(QuestionMiner::class)->mine($site, 90);

    expect($report->queriesRead)->toBe(0)->and($report->clusters)->toBe([]);
});

it('drops a bot topic whose phrasings each sit under the threshold but add up past it', function () {
    $site = Site::factory()->create(['brand_name' => 'Acme Pumps', 'domain_url' => 'https://acmepumps.test']);
    $asOf = CarbonImmutable::parse('2026-11-01');
    foreach (['what to do when your sump pump fails', 'what to do when sump pump fails', 'what to do when the sump pump fails'] as $i => $q) {
        questionGscRow($site, '2026-09-1'.$i, $q, 27 - $i, 0, 1.0, 'https://acmepumps.test/hoboken/');
    }

    $report = (new QuestionMiner(new BotQueryGuard))->mine($site, 90, $asOf);

    expect($report->clusters)->toBe([])
        ->and(count($report->botDropped))->toBe(1)
        ->and($report->botDropped[0]['query'])->toBe('what to do when your sump pump fails')
        ->and($report->botDropped[0]['reason'])->toBe('zero_click')
        ->and($report->botDropped[0]['impressions'])->toBe(78);
});
