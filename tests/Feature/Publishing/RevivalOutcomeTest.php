<?php

use App\Enums\ContentKind;
use App\Enums\ContentStatus;
use App\Models\Content;
use App\Models\GscUrlDaily;
use App\Models\GscUrlQueryDaily;
use App\Models\Site;
use App\Publishing\Redirects\RevivalOutcome;
use App\Support\CurrentSite;
use App\Support\PublicUrl;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;

beforeEach(function () {
    // Frozen mid-afternoon: every date below is derived from now(), on both the fixture and the assertion
    // side, and the hour after midnight UTC would otherwise put them on different days.
    $this->travelTo('2026-09-22 14:00:00');
});

afterEach(function () {
    CurrentSite::clear();
});

function outcomeDay(Site $site, string $url, Carbon $date, int $impressions, int $clicks = 1, float $position = 9.0): void
{
    GscUrlDaily::withoutGlobalScopes()->create([
        'id' => (string) Str::ulid(), 'site_id' => $site->id, 'grain_hash' => Str::random(32),
        'date' => $date->toDateString(), 'url' => $url,
        'impressions' => $impressions, 'clicks' => $clicks, 'position' => $position,
    ]);
}

function outcomeQuery(Site $site, string $url, Carbon $date, string $query, int $impressions): void
{
    GscUrlQueryDaily::withoutGlobalScopes()->create([
        'id' => (string) Str::ulid(), 'site_id' => $site->id, 'grain_hash' => Str::random(32),
        'date' => $date->toDateString(), 'url' => $url, 'query' => $query,
        'country' => 'usa', 'device' => 'desktop', 'impressions' => $impressions, 'clicks' => 0, 'position' => 8.0,
    ]);
}

/** A revived post published $daysAgo days ago that replaced two old URLs. */
function revivedPost(Site $site, int $daysAgo, array $queries = ['sump pump check valve', 'check valve replacement']): array
{
    $post = Content::factory()->create([
        'site_id' => $site->id, 'kind' => ContentKind::Post, 'slug' => 'sump-pump-check-valve-guide',
        'title' => 'Sump Pump Check Valve', 'status' => ContentStatus::Published,
        'published_at' => now()->subDays($daysAgo)->startOfDay(),
        'meta' => [
            'revived_from_urls' => ['/when-to-replace-sump-pump-check-valve-2', '/when-to-replace-sump-pump-check-valve-3'],
            'revived_queries' => array_map(fn (string $q): array => ['query' => $q, 'impressions' => 100], $queries),
        ],
    ]);
    $root = rtrim((string) $site->domain_url, '/');

    return [
        $post,
        (string) PublicUrl::forContent($site->domain_url, $post),
        [$root.'/when-to-replace-sump-pump-check-valve-2/', $root.'/when-to-replace-sump-pump-check-valve-3/'],
    ];
}

it('compares the family before against the post after, the same number of days each side', function () {
    $site = Site::factory()->create();
    [$post, $new, $old] = revivedPost($site, daysAgo: 10);
    $published = $post->published_at;

    // Ten days before: the two old copies earning 50 + 30 a day. Ten days after: the post earning 70.
    for ($d = 1; $d <= 10; $d++) {
        outcomeDay($site, $old[0], $published->copy()->subDays($d), 50);
        outcomeDay($site, $old[1], $published->copy()->subDays($d), 30);
    }
    for ($d = 0; $d < 10; $d++) {
        outcomeDay($site, $new, $published->copy()->addDays($d), 70);
    }

    $r = app(RevivalOutcome::class)->for($site)[0];

    expect($r['days_compared'])->toBe(10)
        ->and($r['before']['impressions'])->toBe(800)
        ->and($r['after']['impressions'])->toBe(700)
        ->and($r['ratio'])->toBe(0.88)
        ->and($r['verdict'])->toBe('held')
        ->and($r['residual'])->toBe(0);
});

it('refuses a verdict on a post Google has barely seen', function () {
    $site = Site::factory()->create();
    [$post, $new, $old] = revivedPost($site, daysAgo: 3);
    outcomeDay($site, $old[0], $post->published_at->copy()->subDay(), 500);
    outcomeDay($site, $new, $post->published_at, 4);

    $r = app(RevivalOutcome::class)->for($site)[0];

    // Three days of data against a family's three days is a comparison; calling it "lost" is not.
    expect($r['verdict'])->toBe('too_early')->and($r['days_compared'])->toBe(3);
});

it('reports what the old URLs still earn after publish', function () {
    $site = Site::factory()->create();
    [$post, $new, $old] = revivedPost($site, daysAgo: 14);
    outcomeDay($site, $old[0], $post->published_at->copy()->subDay(), 200);
    outcomeDay($site, $new, $post->published_at->copy()->addDays(2), 150);
    // The 301 is not landing: the old URL is still being shown a week after publish.
    outcomeDay($site, $old[0], $post->published_at->copy()->addDays(7), 90);

    expect(app(RevivalOutcome::class)->for($site)[0]['residual'])->toBe(90);
});

it('tests the brief directly: which of its queries the post now ranks for', function () {
    $site = Site::factory()->create();
    [$post, $new] = revivedPost($site, daysAgo: 14, queries: ['sump pump check valve', 'check valve replacement', 'check valve failure']);
    outcomeDay($site, $new, $post->published_at->copy()->addDays(3), 100);
    outcomeQuery($site, $new, $post->published_at->copy()->addDays(3), 'sump pump check valve', 60);
    outcomeQuery($site, $new, $post->published_at->copy()->addDays(4), 'Check Valve Replacement', 20);

    $q = app(RevivalOutcome::class)->for($site)[0]['queries'];

    // A post can hold the headline query and lose the ones behind it while its impression count looks fine.
    expect($q['brief'])->toBe(3)
        ->and($q['ranking'])->toBe(2)
        ->and($q['lost'])->toBe(['check valve failure']);
});

it('has no baseline when the family earned nothing before publish', function () {
    $site = Site::factory()->create();
    [$post, $new] = revivedPost($site, daysAgo: 14);
    outcomeDay($site, $new, $post->published_at->copy()->addDay(), 40);

    expect(app(RevivalOutcome::class)->for($site)[0]['verdict'])->toBe('no_baseline');
});

it('prints the comparison and says it is not a cause', function () {
    $site = Site::factory()->create(['brand_name' => 'Sump Pump Gurus']);
    [$post, $new, $old] = revivedPost($site, daysAgo: 10);
    for ($d = 1; $d <= 10; $d++) {
        outcomeDay($site, $old[0], $post->published_at->copy()->subDays($d), 100);
    }
    for ($d = 0; $d < 10; $d++) {
        outcomeDay($site, $new, $post->published_at->copy()->addDays($d), 40);
    }

    $this->artisan('launchpad:report-revivals', ['--site' => $site->id])
        ->expectsOutputToContain('Sump Pump Check Valve')
        ->expectsOutputToContain('comparing 10 day(s) each side')
        ->expectsOutputToContain('1 lost')
        ->expectsOutputToContain('not a cause')
        ->assertSuccessful();
});

it('says so when nothing revived has been published', function () {
    $site = Site::factory()->create(['brand_name' => 'Sump Pump Gurus']);

    $this->artisan('launchpad:report-revivals', ['--site' => $site->id])
        ->expectsOutputToContain('No revived post has been published yet')
        ->assertSuccessful();
});
