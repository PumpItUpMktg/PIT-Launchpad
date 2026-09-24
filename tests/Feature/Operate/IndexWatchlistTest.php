<?php

use App\Enums\ContentKind;
use App\Enums\ContentStatus;
use App\Enums\IndexCoverageState;
use App\Enums\PageType;
use App\Enums\UserRole;
use App\Filament\Pages\IndexingBoard;
use App\Models\Content;
use App\Models\GscUrlDaily;
use App\Models\PageIndexState;
use App\Models\Site;
use App\Models\User;
use App\Operator\ActiveTenant;
use App\Operator\Coverage\IndexWatchlist;
use App\Support\PublicUrl;
use Filament\Facades\Filament;
use Livewire\Livewire;

function watchSite(): Site
{
    return Site::factory()->create(['domain_url' => 'https://watch.example']);
}

function watchPage(Site $site, string $title, string $publishedAt, string $slug): Content
{
    return Content::factory()->create([
        'site_id' => $site->id, 'kind' => ContentKind::Page, 'page_type' => PageType::Location,
        'status' => ContentStatus::Published, 'title' => $title, 'slug' => $slug, 'published_at' => $publishedAt, 'wp_post_id' => 1,
    ]);
}

function watchVerdict(Site $site, Content $page, string $verdict, string $inspectedAt, ?string $indexedAt = null): void
{
    $url = PublicUrl::forContent($site->domain_url, $page);
    PageIndexState::create([
        'site_id' => $site->id, 'content_id' => $page->id, 'url' => $url, 'url_normalized' => rtrim((string) $url, '/'),
        'coverage_state' => $verdict, 'index_verdict' => $verdict, 'last_inspected_at' => $inspectedAt, 'indexed_at' => $indexedAt,
    ]);
}

beforeEach(fn () => $this->travelTo('2026-09-24 12:00:00'));

it('lists published pages by state — plain until inspected, amber when inspected and not indexed, green when it lands', function () {
    $site = watchSite();
    $plain = watchPage($site, 'Plain Town', '2026-09-20 09:00:00', 'plain-town');
    $amber = watchPage($site, 'Amber Town', '2026-09-10 09:00:00', 'amber-town');
    $green = watchPage($site, 'Green Town', '2026-09-15 09:00:00', 'green-town');
    watchVerdict($site, $amber, IndexCoverageState::CrawledNotIndexed->value, '2026-09-22 03:00:00');
    watchVerdict($site, $green, 'PASS', '2026-09-23 03:00:00', indexedAt: '2026-09-22 03:00:00');

    $list = app(IndexWatchlist::class)->for($site);

    expect($list['waiting'])->toBe(1)->and($list['inspected'])->toBe(1)->and($list['landed'])->toBe(1)
        ->and(collect($list['rows'])->pluck('state', 'title')->all())->toBe(['Amber Town' => 'inspected', 'Plain Town' => 'published', 'Green Town' => 'indexed']); // waiting oldest-first, then landed

    $rows = collect($list['rows'])->keyBy('title');
    expect($rows['Amber Town']['reason'])->toBe('Crawled — not indexed')
        ->and($rows['Amber Town']['inspected_at'])->toBe('2026-09-22')
        ->and($rows['Amber Town']['days_waiting'])->toBe(14)
        ->and($rows['Plain Town']['inspected_at'])->toBeNull()
        ->and($rows['Green Town']['indexed_at'])->toBe('2026-09-22')
        ->and($rows['Green Town']['days_waiting'])->toBe(7)   // published → indexed
        ->and($rows['Green Town']['url'])->toBe('https://watch.example/green-town/');
});

it('drops an indexed page off the list once it has been indexed for longer than the watch window', function () {
    config(['launchpad.indexing.watch_days' => 5]);
    $site = watchSite();
    $fresh = watchPage($site, 'Fresh', '2026-09-01 09:00:00', 'fresh');
    $old = watchPage($site, 'Old', '2026-09-01 09:00:00', 'old');
    $unstamped = watchPage($site, 'Unstamped', '2026-09-01 09:00:00', 'unstamped');
    watchVerdict($site, $fresh, 'PASS', '2026-09-24 03:00:00', indexedAt: '2026-09-20 03:00:00'); // 4 days → still shown
    watchVerdict($site, $old, 'PASS', '2026-09-24 03:00:00', indexedAt: '2026-09-18 03:00:00');   // 6 days → gone
    watchVerdict($site, $unstamped, 'PASS', '2026-09-24 03:00:00');                              // no date → long indexed → gone

    $titles = collect(app(IndexWatchlist::class)->for($site)['rows'])->pluck('title')->all();

    expect($titles)->toBe(['Fresh']);
});

it('counts a page earning impressions as indexed from its first impression, even with no PASS verdict', function () {
    $site = watchSite();
    $page = watchPage($site, 'Seen In Search', '2026-09-10 09:00:00', 'seen');
    watchVerdict($site, $page, IndexCoverageState::CrawledNotIndexed->value, '2026-09-12 03:00:00'); // a stale "not indexed"
    foreach (['2026-09-21', '2026-09-23'] as $date) {
        GscUrlDaily::create(['site_id' => $site->id, 'grain_hash' => hash('sha256', $site->id.'|'.$date.'|seen'), 'date' => $date, 'url' => 'https://watch.example/seen/', 'impressions' => 3, 'clicks' => 0, 'ctr' => 0]);
    }

    $rows = collect(app(IndexWatchlist::class)->for($site)['rows'])->keyBy('title');

    expect($rows['Seen In Search']['state'])->toBe('indexed')
        ->and($rows['Seen In Search']['indexed_at'])->toBe('2026-09-21');
});

it('renders the watchlist block on the Indexing board, and an empty state when nothing is waiting', function () {
    Filament::setCurrentPanel('admin');
    $this->actingAs(User::factory()->create(['role' => UserRole::Operator]));
    $site = watchSite();
    $page = watchPage($site, 'Waiting Town', '2026-09-18 09:00:00', 'waiting-town');
    watchVerdict($site, $page, IndexCoverageState::DiscoveredNotIndexed->value, '2026-09-23 03:00:00');
    app(ActiveTenant::class)->set($site->id);

    $html = Livewire::test(IndexingBoard::class)->assertOk()->html();
    expect($html)->toContain('Waiting on Google')
        ->toContain('Waiting Town')
        ->toContain('Discovered — not indexed')
        ->toContain('18 Sep 2026')
        ->toContain('is-inspected');

    PageIndexState::withoutGlobalScopes()->update(['index_verdict' => 'PASS', 'indexed_at' => '2026-09-10 00:00:00']); // long indexed → off the list
    expect(Livewire::test(IndexingBoard::class)->html())->toContain('Nothing waiting');
});
