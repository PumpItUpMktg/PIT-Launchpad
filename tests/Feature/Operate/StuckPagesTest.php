<?php

use App\Enums\ContentKind;
use App\Enums\ContentStatus;
use App\Enums\IndexCoverageState;
use App\Enums\PageType;
use App\Models\Content;
use App\Models\Location;
use App\Models\PageIndexState;
use App\Models\Site;
use App\Operator\Coverage\IndexWatchlist;
use App\Operator\Coverage\StuckPages;
use App\Support\PublicUrl;

function stuckSite(): Site
{
    return Site::factory()->create(['domain_url' => 'https://stuck.example', 'brand_name' => 'Stuck Co']);
}

function stuckPage(Site $site, string $title, string $publishedAt, string $slug, ?string $marketId = null, ?string $body = null): Content
{
    return Content::factory()->create([
        'site_id' => $site->id, 'kind' => ContentKind::Page, 'page_type' => PageType::Location, 'status' => ContentStatus::Published,
        'title' => $title, 'slug' => $slug, 'published_at' => $publishedAt, 'wp_post_id' => 1, 'parent_location_id' => $marketId, 'body' => $body,
    ]);
}

function stuckVerdict(Site $site, Content $page, string $verdict): void
{
    $url = PublicUrl::forContent($site->domain_url, $page);
    PageIndexState::create([
        'site_id' => $site->id, 'content_id' => $page->id, 'url' => $url, 'url_normalized' => rtrim((string) $url, '/'),
        'coverage_state' => $verdict, 'index_verdict' => $verdict, 'last_inspected_at' => '2026-09-24 03:00:00',
    ]);
}

beforeEach(fn () => $this->travelTo('2026-09-25 12:00:00'));

it('assigns each stuck page the lever its reason and inbound links call for, oldest first within a lever', function () {
    $site = stuckSite();
    $market = Location::factory()->create(['site_id' => $site->id, 'name' => 'Doylestown']);

    $linkMe = stuckPage($site, 'Unlinked Discovered', '2026-09-01 09:00:00', 'unlinked-discovered', $market->id);
    stuckVerdict($site, $linkMe, IndexCoverageState::DiscoveredNotIndexed->value);

    $pingMe = stuckPage($site, 'Linked Discovered', '2026-09-05 09:00:00', 'linked-discovered', $market->id);
    stuckVerdict($site, $pingMe, IndexCoverageState::DiscoveredNotIndexed->value);
    // An indexed page that links to it.
    $hub = stuckPage($site, 'Hub', '2026-06-01 09:00:00', 'hub', $market->id, '<p><a href="'.PublicUrl::forContent($site->domain_url, $pingMe).'">Linked</a></p>');
    stuckVerdict($site, $hub, 'PASS');

    $regen = stuckPage($site, 'Crawled Linked', '2026-09-03 09:00:00', 'crawled-linked', $market->id);
    stuckVerdict($site, $regen, IndexCoverageState::CrawledNotIndexed->value);
    $hubTwo = stuckPage($site, 'Hub Two', '2026-06-01 09:00:00', 'hub-two', $market->id, '<p><a href="'.PublicUrl::forContent($site->domain_url, $regen).'">Linked</a></p>');
    stuckVerdict($site, $hubTwo, 'PASS');

    $crawledUnlinked = stuckPage($site, 'Crawled Unlinked', '2026-09-02 09:00:00', 'crawled-unlinked', $market->id);
    stuckVerdict($site, $crawledUnlinked, IndexCoverageState::CrawledNotIndexed->value);

    $never = stuckPage($site, 'Never Inspected', '2026-09-04 09:00:00', 'never-inspected', $market->id);

    $blocked = stuckPage($site, 'Blocked', '2026-09-06 09:00:00', 'blocked', $market->id);
    stuckVerdict($site, $blocked, IndexCoverageState::ExcludedBlocked->value);

    // Not stuck: too recent, correctly excluded, or indexed.
    stuckPage($site, 'Fresh', '2026-09-20 09:00:00', 'fresh', $market->id);
    $canon = stuckPage($site, 'Canonical Elsewhere', '2026-08-01 09:00:00', 'canonical-elsewhere', $market->id);
    stuckVerdict($site, $canon, IndexCoverageState::ExcludedCanonical->value);

    $report = app(StuckPages::class)->for($site);

    $byTitle = collect($report['rows'])->keyBy('title');
    expect($byTitle->keys()->all())->toBe(['Unlinked Discovered', 'Crawled Unlinked', 'Linked Discovered', 'Never Inspected', 'Crawled Linked', 'Blocked']) // lever order (link, ping, recheck, regenerate, unblock), oldest first inside
        ->and($byTitle['Unlinked Discovered']['lever'])->toBe(StuckPages::LINK)
        ->and($byTitle['Unlinked Discovered']['inbound'])->toBe(0)
        ->and($byTitle['Crawled Unlinked']['lever'])->toBe(StuckPages::LINK)
        ->and($byTitle['Linked Discovered']['lever'])->toBe(StuckPages::PING)
        ->and($byTitle['Linked Discovered']['inbound'])->toBe(1)
        ->and($byTitle['Crawled Linked']['lever'])->toBe(StuckPages::REGENERATE)
        ->and($byTitle['Never Inspected']['lever'])->toBe(StuckPages::RECHECK)
        ->and($byTitle['Blocked']['lever'])->toBe(StuckPages::UNBLOCK)
        ->and($report['by_lever'])->toBe([StuckPages::LINK => 2, StuckPages::PING => 1, StuckPages::RECHECK => 1, StuckPages::REGENERATE => 1, StuckPages::UNBLOCK => 1])
        ->and($report['markets_needing_links'])->toBe([$market->id]);
});

it('a correct exclusion (redirect / canonical) is not waiting — off the list and out of the counts', function () {
    $site = stuckSite();
    $canon = stuckPage($site, 'Canonical Elsewhere', '2026-08-01 09:00:00', 'canonical-elsewhere');
    stuckVerdict($site, $canon, IndexCoverageState::ExcludedCanonical->value);
    $redirect = stuckPage($site, 'Moved', '2026-08-01 09:00:00', 'moved');
    stuckVerdict($site, $redirect, IndexCoverageState::ExcludedRedirect->value);
    $waiting = stuckPage($site, 'Waiting', '2026-08-01 09:00:00', 'waiting');
    stuckVerdict($site, $waiting, IndexCoverageState::CrawledNotIndexed->value);

    $list = app(IndexWatchlist::class)->for($site);

    expect(collect($list['rows'])->pluck('title')->all())->toBe(['Waiting'])
        ->and($list['inspected'])->toBe(1)
        ->and($list['metrics']['not_indexed'])->toBe(1)
        ->and($list['metrics']['stuck'])->toBe(1);
});

it('prints the report grouped by lever with the next commands', function () {
    $site = stuckSite();
    $market = Location::factory()->create(['site_id' => $site->id, 'name' => 'Doylestown']);
    $page = stuckPage($site, 'Unlinked Town', '2026-09-01 09:00:00', 'unlinked-town', $market->id);
    stuckVerdict($site, $page, IndexCoverageState::DiscoveredNotIndexed->value);

    $this->artisan('launchpad:report-stuck-pages', ['--site' => 'Stuck Co'])
        ->expectsOutputToContain('1 page(s) not indexed after 10+ days')
        ->expectsOutputToContain('LINK IT — no inbound links')
        ->expectsOutputToContain('Unlinked Town')
        ->expectsOutputToContain("launchpad:plan-links {$site->id} --market={$market->id}")
        ->expectsOutputToContain("launchpad:indexnow --site={$site->id}")
        ->assertSuccessful();

    $this->artisan('launchpad:report-stuck-pages')->expectsOutputToContain('Pass --site=')->assertFailed();
});
