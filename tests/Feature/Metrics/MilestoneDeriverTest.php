<?php

use App\Enums\ContentKind;
use App\Enums\ContentStatus;
use App\Metrics\Milestones\MilestoneDeriver;
use App\Models\ClientMilestone;
use App\Models\Content;
use App\Models\Site;
use App\Support\CurrentSite;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

afterEach(function () {
    CurrentSite::clear();
});

function mdSnap(Site $site, string $provider, string $metric, string $dimType, string $dimValue, string $date, float $value, ?array $json = null): void
{
    DB::table('metric_snapshots')->insert([
        'id' => (string) Str::ulid(),
        'site_id' => $site->id,
        'provider' => $provider,
        'metric_key' => $metric,
        'dimension_type' => $dimType,
        'dimension_value' => $dimValue,
        'period_grain' => 'day',
        'period_date' => $date,
        'value_numeric' => $value,
        'value_json' => $json !== null ? json_encode($json) : null,
        'captured_at' => now(),
        'created_at' => now(),
        'updated_at' => now(),
    ]);
}

/** @return array<string, ClientMilestone> keyed by milestone key. */
function milestones(Site $site): array
{
    return ClientMilestone::withoutGlobalScopes()->where('site_id', $site->id)->get()->keyBy('key')->all();
}

it('derives first-impression and first-click from the earliest positive GSC day', function () {
    $site = Site::factory()->create();
    mdSnap($site, 'gsc', 'impressions', 'site', '', '2026-06-01', 0);   // zero — ignored
    mdSnap($site, 'gsc', 'impressions', 'site', '', '2026-06-05', 12);  // first positive
    mdSnap($site, 'gsc', 'impressions', 'site', '', '2026-06-10', 40);
    mdSnap($site, 'gsc', 'clicks', 'site', '', '2026-06-09', 3);

    app(MilestoneDeriver::class)->derive($site);
    $m = milestones($site);

    expect($m['first_impression']->occurred_on->toDateString())->toBe('2026-06-05')
        ->and($m['first_impression']->payload['value'])->toBe(12)
        ->and($m['first_impression']->is_client_visible)->toBeTrue()
        ->and($m['first_click']->occurred_on->toDateString())->toBe('2026-06-09');
});

it('derives first-page-indexed from the earliest day a page was indexed', function () {
    $site = Site::factory()->create();
    mdSnap($site, 'index', 'pages_indexed', 'site', '', '2026-07-01', 0);
    mdSnap($site, 'index', 'pages_indexed', 'site', '', '2026-07-03', 2);

    app(MilestoneDeriver::class)->derive($site);

    expect(milestones($site)['first_page_indexed']->occurred_on->toDateString())->toBe('2026-07-03');
});

it('derives the first page-one keyword with its query and rank', function () {
    $site = Site::factory()->create();
    mdSnap($site, 'dataforseo', 'rank', 'keyword', 'kwA', '2026-06-20', 15, ['query' => 'too deep']); // not top10
    mdSnap($site, 'dataforseo', 'rank', 'keyword', 'kwA', '2026-07-01', 8, ['query' => 'sump pump repair']); // first ≤10
    mdSnap($site, 'dataforseo', 'rank', 'keyword', 'kwB', '2026-07-10', 3, ['query' => 'french drain']);

    app(MilestoneDeriver::class)->derive($site);
    $m = milestones($site)['first_top10_keyword'];

    expect($m->occurred_on->toDateString())->toBe('2026-07-01')
        ->and($m->payload['query'])->toBe('sump pump repair')
        ->and($m->payload['rank'])->toBe(8)
        ->and($m->payload['keyword_id'])->toBe('kwA');
});

it('derives blog-volume milestones as the Nth post goes live', function () {
    $site = Site::factory()->create();
    for ($i = 1; $i <= 12; $i++) {
        Content::factory()->create([
            'site_id' => $site->id, 'kind' => ContentKind::Post, 'status' => ContentStatus::Published,
            'slug' => "post-{$i}", 'title' => "Post {$i}", 'published_at' => '2026-06-'.sprintf('%02d', $i).' 12:00:00',
        ]);
    }

    app(MilestoneDeriver::class)->derive($site);
    $m = milestones($site);

    // 12 posts → blog_post_10 hits on the 10th post's date; 50/100 not reached.
    expect($m['blog_post_10']->occurred_on->toDateString())->toBe('2026-06-10')
        ->and($m['blog_post_10']->payload['count'])->toBe(10)
        ->and($m)->not->toHaveKey('blog_post_50')
        ->and($m)->not->toHaveKey('blog_post_100');
});

it('creates no milestone that has not been reached', function () {
    $site = Site::factory()->create(); // no spine data, no posts

    app(MilestoneDeriver::class)->derive($site);

    expect(ClientMilestone::withoutGlobalScopes()->where('site_id', $site->id)->count())->toBe(0);
});

it('is idempotent — a re-derive updates in place, never duplicates', function () {
    $site = Site::factory()->create();
    mdSnap($site, 'gsc', 'impressions', 'site', '', '2026-06-05', 12);

    $deriver = app(MilestoneDeriver::class);
    $deriver->derive($site);
    $deriver->derive($site);

    expect(ClientMilestone::withoutGlobalScopes()->where('site_id', $site->id)->where('key', 'first_impression')->count())->toBe(1);
});

it('derive-milestones command runs for a site', function () {
    $site = Site::factory()->create();
    mdSnap($site, 'gsc', 'clicks', 'site', '', '2026-06-09', 3);

    $this->artisan('sandhog:derive-milestones', ['site' => $site->id])->assertSuccessful();

    expect(ClientMilestone::withoutGlobalScopes()->where('site_id', $site->id)->where('key', 'first_click')->exists())->toBeTrue();
});
