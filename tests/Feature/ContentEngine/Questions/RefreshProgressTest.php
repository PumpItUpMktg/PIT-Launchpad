<?php

use App\ContentEngine\Questions\RefreshProgress;
use App\Models\Content;
use App\Models\GscUrlDaily;
use App\Models\GscUrlQueryDaily;
use App\Models\Site;
use Carbon\Carbon;

function rpQuery(Site $s, string $url, string $query, string $date, int $impr, int $clicks, ?float $pos): void
{
    GscUrlQueryDaily::create(['site_id' => $s->id, 'grain_hash' => hash('sha256', $url.'|'.$query.'|'.$date), 'date' => $date, 'url' => $url, 'query' => $query,
        'country' => 'usa', 'device' => 'DESKTOP', 'impressions' => $impr, 'clicks' => $clicks, 'ctr' => 0, 'position' => $pos]);
}

function rpDay(Site $s, string $url, string $date, int $impr, int $clicks, ?float $pos): void
{
    GscUrlDaily::create(['site_id' => $s->id, 'grain_hash' => hash('sha256', $url.'|'.$date), 'date' => $date, 'url' => $url, 'impressions' => $impr, 'clicks' => $clicks, 'ctr' => 0, 'position' => $pos]);
}

it('reads the question and the page over equal windows either side of the refresh, by path, impression-weighted', function () {
    $site = Site::factory()->create(['domain_url' => 'https://spg.example']);
    $post = Content::factory()->post()->published()->create(['site_id' => $site->id, 'slug' => 'cost', 'title' => 'Cost', 'body' => '<p>x</p>',
        'meta' => ['question_refresh' => ['query' => 'sump pump installation cost', 'refreshed_at' => '2026-11-07T10:00:00+00:00']]]);
    $url = 'https://spg.example/cost/';
    // Before (Oct 24 – Nov 6): the question at ~58 on 100 impressions, 0 clicks; the page 400 impr / 4 clicks.
    rpQuery($site, $url, 'sump pump installation cost', '2026-10-30', 60, 0, 60.0);
    rpQuery($site, $url, 'sump pump installation cost', '2026-11-05', 40, 0, 55.0);
    rpQuery($site, $url, 'unrelated query', '2026-11-05', 500, 9, 3.0);                 // another query — not the question
    rpQuery($site, 'https://spg.example/other/', 'sump pump installation cost', '2026-11-05', 900, 9, 2.0); // another page
    rpDay($site, $url, '2026-10-30', 250, 2, 30.0);
    rpDay($site, 'http://spg.example/cost', '2026-11-05', 150, 2, 20.0);                 // same path, other scheme / no slash
    rpDay($site, $url, '2026-10-20', 999, 99, 1.0);                                      // outside the before window
    // After (Nov 7 – Nov 20): the question at ~41 on 190 impressions, 3 clicks; the page 600 impr / 8 clicks.
    rpQuery($site, $url, 'sump pump installation cost', '2026-11-07', 90, 1, 45.0);
    rpQuery($site, $url, 'sump pump installation cost', '2026-11-18', 100, 2, 38.0);
    rpDay($site, $url, '2026-11-10', 600, 8, 25.0);
    rpDay($site, $url, '2026-11-25', 999, 99, 1.0);                                      // outside the after window

    $p = app(RefreshProgress::class)->for($site, $post, Carbon::parse('2026-11-21 09:00:00'));

    expect($p['refreshed_at'])->toBe('2026-11-07')->and($p['days_since'])->toBe(14)->and($p['window_days'])->toBe(14)->and($p['thin'])->toBeFalse()
        ->and($p['question']['query'])->toBe('sump pump installation cost')
        ->and($p['question']['before'])->toBe(['impressions' => 100, 'clicks' => 0, 'position' => 58.0])
        ->and($p['question']['after'])->toBe(['impressions' => 190, 'clicks' => 3, 'position' => 41.3])
        ->and($p['page']['before'])->toBe(['impressions' => 400, 'clicks' => 4, 'position' => 26.3])
        ->and($p['page']['after'])->toBe(['impressions' => 600, 'clicks' => 8, 'position' => 25.0]);
});

it('caps the window at 28 days, flags a read under a week as early, and is null before a day has passed or without a mark', function () {
    $site = Site::factory()->create(['domain_url' => 'https://spg.example']);
    $old = Content::factory()->post()->published()->create(['site_id' => $site->id, 'slug' => 'old', 'body' => '<p>x</p>', 'meta' => ['question_refresh' => ['query' => 'q', 'refreshed_at' => '2026-08-01T00:00:00+00:00']]]);
    $p = app(RefreshProgress::class)->for($site, $old, Carbon::parse('2026-11-21'));
    expect($p['window_days'])->toBe(28)->and($p['days_since'])->toBe(112)->and($p['question']['before']['position'])->toBeNull();

    $fresh = Content::factory()->post()->published()->create(['site_id' => $site->id, 'slug' => 'fresh', 'body' => '<p>x</p>', 'meta' => ['question_refresh' => ['query' => 'q', 'refreshed_at' => '2026-11-19T00:00:00+00:00']]]);
    expect(app(RefreshProgress::class)->for($site, $fresh, Carbon::parse('2026-11-21'))['thin'])->toBeTrue();

    $today = Content::factory()->post()->published()->create(['site_id' => $site->id, 'slug' => 'today', 'body' => '<p>x</p>', 'meta' => ['question_refresh' => ['query' => 'q', 'refreshed_at' => '2026-11-21T08:00:00+00:00']]]);
    expect(app(RefreshProgress::class)->for($site, $today, Carbon::parse('2026-11-21 09:00:00')))->toBeNull();

    $none = Content::factory()->post()->published()->create(['site_id' => $site->id, 'slug' => 'none', 'body' => '<p>x</p>', 'meta' => []]);
    expect(app(RefreshProgress::class)->for($site, $none))->toBeNull();
});
