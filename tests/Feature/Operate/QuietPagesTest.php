<?php

use App\Enums\ContentStatus;
use App\Enums\PageType;
use App\Enums\UserRole;
use App\Models\Content;
use App\Models\GscUrlDaily;
use App\Models\Keyword;
use App\Models\PageIndexState;
use App\Models\Site;
use App\Models\User;
use App\Operator\Coverage\QuietPages;
use App\Support\CurrentSite;
use App\Support\PublicUrl;
use Illuminate\Support\Str;

beforeEach(function () {
    $this->actingAs(User::factory()->create(['role' => UserRole::Operator]));
});

afterEach(function () {
    CurrentSite::clear();
});

function quietPage(Site $site, string $slug, array $attributes = []): Content
{
    $page = Content::factory()->create(array_merge([
        'site_id' => $site->id,
        'slug' => $slug,
        'title' => ucfirst(str_replace('-', ' ', $slug)),
        'status' => ContentStatus::Published,
        'page_type' => PageType::Location,
        'published_at' => now()->subYear(),
    ], $attributes));

    $url = (string) PublicUrl::forContent($site->domain_url, $page);
    PageIndexState::withoutGlobalScopes()->create([
        'site_id' => $site->id,
        'content_id' => $page->id,
        'url' => $url,
        'url_normalized' => rtrim($url, '/'),
        'coverage_state' => 'indexed',
        'index_verdict' => 'PASS',
        'last_inspected_at' => now()->subDay(),
    ]);

    return $page;
}

function quietImpression(Site $site, Content $page, int $daysAgo): void
{
    GscUrlDaily::withoutGlobalScopes()->create([
        'id' => (string) Str::ulid(),
        'site_id' => $site->id,
        'grain_hash' => Str::random(32),
        'date' => now()->subDays($daysAgo)->toDateString(),
        'url' => (string) PublicUrl::forContent($site->domain_url, $page),
        'impressions' => 25,
        'clicks' => 1,
        'position' => 14.0,
    ]);
}

function quietTarget(Site $site, Content $page, ?int $volume): void
{
    $keyword = Keyword::factory()->create(['site_id' => $site->id, 'volume' => $volume]);
    $page->forceFill(['target_keyword_id' => $keyword->id])->save();
}

it('separates a page that never earned from one that went quiet', function () {
    $site = Site::factory()->create();
    $never = quietPage($site, 'never-ranked-nj');
    $lapsed = quietPage($site, 'used-to-rank-nj');
    quietImpression($site, $lapsed, 120);   // earned last quarter, nothing since

    $r = app(QuietPages::class)->for($site);

    expect($r['total'])->toBe(2)
        ->and($r['never_earned'])->toBe(1)
        ->and($r['lapsed'])->toBe(1);

    // Both are indexed by PASS; the difference is a before to compare to, not a verdict.
    expect($never->fresh()->status)->toBe(ContentStatus::Published);
});

it('does not count a page that is still earning', function () {
    $site = Site::factory()->create();
    $earning = quietPage($site, 'busy-page-nj');
    quietImpression($site, $earning, 3);

    $r = app(QuietPages::class)->for($site);

    expect($r['total'])->toBe(0)->and($r['indexed_total'])->toBe(1);
});

it('marks a page published last week as too young to judge', function () {
    $site = Site::factory()->create();
    quietPage($site, 'brand-new-nj', ['published_at' => now()->subDays(5)]);

    $r = app(QuietPages::class)->for($site);

    expect($r['total'])->toBe(1)->and($r['too_young'])->toBe(1)
        // Young pages never reach the actionable list — nothing has gone wrong yet.
        ->and($r['actionable'])->toBe([]);
});

it('treats zero impressions on a no-demand term as the correct outcome', function () {
    $site = Site::factory()->create();
    $hamlet = quietPage($site, 'tiny-hamlet-nj');
    quietTarget($site, $hamlet, 0);

    $r = app(QuietPages::class)->for($site);

    expect($r['by_demand']['thin'])->toBe(1)
        ->and($r['by_demand']['real'])->toBe(0)
        // Nothing to act on: the page was never going to earn impressions.
        ->and($r['actionable'])->toBe([]);
});

it('surfaces the intersection worth acting on', function () {
    $site = Site::factory()->create();
    $wasted = quietPage($site, 'sump-pump-repair-trenton-nj');
    quietTarget($site, $wasted, 480);

    $r = app(QuietPages::class)->for($site);

    expect($r['by_demand']['real'])->toBe(1)
        ->and($r['actionable'])->toHaveCount(1)
        ->and($r['actionable'][0]['volume'])->toBe(480)
        ->and($r['actionable'][0]['inbound'])->toBe(0)
        ->and($r['actionable'][0]['lapsed'])->toBeFalse();
});

it('counts the orphans — reachable by sitemap, unsupported by the site', function () {
    $site = Site::factory()->create();
    quietPage($site, 'nobody-links-here-nj');

    expect(app(QuietPages::class)->for($site)['no_inbound_links'])->toBe(1);
});

it('reports by lane and explains the overlap', function () {
    $site = Site::factory()->create(['brand_name' => 'Sump Pump Gurus']);
    quietPage($site, 'quiet-town-nj');
    quietPage($site, 'quiet-service', ['page_type' => PageType::Service]);

    $this->artisan('launchpad:report-quiet-pages', ['--site' => $site->id])
        ->expectsOutputToContain('earned no impressions in the last 28 days')
        ->expectsOutputToContain('Zero impressions is the CORRECT outcome here')
        ->expectsOutputToContain('four readings of the same set, not a split of it')
        ->assertSuccessful();
});

it('refuses to guess which site', function () {
    $this->artisan('launchpad:report-quiet-pages')->assertFailed();
});
