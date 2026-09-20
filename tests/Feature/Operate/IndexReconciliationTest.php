<?php

use App\Enums\ContentStatus;
use App\Enums\IndexCoverageState;
use App\Enums\UserRole;
use App\Models\Content;
use App\Models\GscUrlDaily;
use App\Models\PageIndexState;
use App\Models\Site;
use App\Models\User;
use App\Operator\Coverage\IndexReconciliation;
use App\Support\CurrentSite;
use App\Support\PublicUrl;
use Illuminate\Support\Str;

beforeEach(function () {
    $this->actingAs(User::factory()->create(['role' => UserRole::Operator]));
});

afterEach(function () {
    CurrentSite::clear();
});

function reconPage(Site $site, string $slug): Content
{
    return Content::factory()->create([
        'site_id' => $site->id,
        'slug' => $slug,
        'title' => ucfirst($slug),
        'status' => ContentStatus::Published,
    ]);
}

function reconVerdict(Site $site, Content $page, string $verdict): void
{
    $url = (string) PublicUrl::forContent($site->domain_url, $page);
    PageIndexState::withoutGlobalScopes()->create([
        'site_id' => $site->id,
        'content_id' => $page->id,
        'url' => $url,
        'url_normalized' => rtrim($url, '/'),
        'coverage_state' => $verdict === 'PASS' ? 'indexed' : $verdict,
        'index_verdict' => $verdict,
        'last_inspected_at' => now()->subDay(),
    ]);
}

function reconImpressions(Site $site, Content $page, int $daysAgo = 3, int $impressions = 40): void
{
    GscUrlDaily::withoutGlobalScopes()->create([
        'id' => (string) Str::ulid(),
        'site_id' => $site->id,
        'grain_hash' => Str::random(32),
        'date' => now()->subDays($daysAgo)->toDateString(),
        'url' => (string) PublicUrl::forContent($site->domain_url, $page),
        'impressions' => $impressions,
        'clicks' => 2,
        'position' => 9.0,
    ]);
}

it('counts a page the cards call indexed and the board does not', function () {
    $site = Site::factory()->create();

    // The disagreement in miniature: inspected BEFORE it got indexed, and now earning impressions.
    $stale = reconPage($site, 'sump-pump-repair');
    reconVerdict($site, $stale, IndexCoverageState::CrawledNotIndexed->value);
    reconImpressions($site, $stale);

    $r = app(IndexReconciliation::class)->for($site);

    expect($r['disagreements'])->toBe(1)
        ->and($r['causes']['impressions_but_verdict_says_no'])->toHaveCount(1)
        ->and($r['causes']['impressions_but_verdict_says_no'][0]['title'])->toBe('Sump-pump-repair')
        ->and($r['causes']['impressions_but_never_inspected'])->toBe([])
        // The board counts the PASS verdicts it has; the card ORs in the impressions.
        ->and($r['board']['indexed'])->toBe(0)
        ->and($r['cards']['indexed'])->toBe(1);
});

it('separates a page the board cannot see at all from one it judged wrongly', function () {
    $site = Site::factory()->create();

    $judged = reconPage($site, 'basement-waterproofing');
    reconVerdict($site, $judged, IndexCoverageState::DiscoveredNotIndexed->value);
    reconImpressions($site, $judged);

    $unseen = reconPage($site, 'french-drain');   // never inspected, but earning impressions
    reconImpressions($site, $unseen);

    $r = app(IndexReconciliation::class)->for($site);

    expect($r['disagreements'])->toBe(2)
        ->and($r['causes']['impressions_but_verdict_says_no'])->toHaveCount(1)
        ->and($r['causes']['impressions_but_never_inspected'])->toHaveCount(1)
        ->and($r['causes']['impressions_but_never_inspected'][0]['verdict'])->toBe('never inspected')
        // Two published pages, one verdict row: the board's denominator is half the cards'.
        ->and($r['board']['inspected'])->toBe(1)
        ->and($r['cards']['published'])->toBe(2);
});

it('does not call a PASS verdict with no impressions a disagreement', function () {
    $site = Site::factory()->create();
    $quiet = reconPage($site, 'crawl-space-encapsulation');
    reconVerdict($site, $quiet, 'PASS');

    $r = app(IndexReconciliation::class)->for($site);

    // Both surfaces say indexed — the card ORs, so PASS alone is enough. Nothing to reconcile.
    expect($r['disagreements'])->toBe(0)
        ->and($r['board']['indexed'])->toBe(1)
        ->and($r['cards']['indexed'])->toBe(1)
        ->and($r['causes']['pass_without_recent_impressions'])->toBe(1);
});

it('flags impressions that fall outside the card window', function () {
    $site = Site::factory()->create();
    $lapsed = reconPage($site, 'sewer-line-repair');
    reconImpressions($site, $lapsed, daysAgo: 120);   // earned impressions last quarter, none since

    $r = app(IndexReconciliation::class)->for($site);

    // The card no longer counts it as in Google; the client dashboard (all-time) still would. Named as
    // its own line rather than folded into the disagreement count — it is a different question.
    expect($r['causes']['impressions_outside_window'])->toBe(1)
        ->and($r['disagreements'])->toBe(0)
        ->and($r['cards']['unchecked'])->toBe(1);
});

it('reports a site where the two surfaces agree as having nothing to explain', function () {
    $site = Site::factory()->create();
    $page = reconPage($site, 'radon-mitigation');
    reconVerdict($site, $page, 'PASS');
    reconImpressions($site, $page);

    $r = app(IndexReconciliation::class)->for($site);

    expect($r['disagreements'])->toBe(0)
        ->and($r['causes']['never_inspected_total'])->toBe(0);
});

it('prints the two surfaces side by side and names the cause', function () {
    $site = Site::factory()->create(['brand_name' => 'Sump Pump Gurus']);
    $stale = reconPage($site, 'sump-pump-installation');
    reconVerdict($site, $stale, IndexCoverageState::CrawledNotIndexed->value);
    reconImpressions($site, $stale);

    $this->artisan('launchpad:report-index-mismatch', ['--site' => $site->id])
        ->expectsOutputToContain('Indexing board vs per-page chips')
        ->expectsOutputToContain('1 page(s) the cards call indexed and the board does not')
        ->expectsOutputToContain('Impressions, but the verdict is not PASS')
        ->assertSuccessful();
});

it('refuses to guess which site', function () {
    $this->artisan('launchpad:report-index-mismatch')->assertFailed();
});
