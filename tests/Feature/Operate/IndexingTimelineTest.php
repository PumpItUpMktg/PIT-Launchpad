<?php

use App\Enums\ContentKind;
use App\Enums\ContentStatus;
use App\Enums\IndexCoverageState;
use App\Enums\PageType;
use App\Enums\UserRole;
use App\Filament\Pages\IndexingBoard;
use App\Models\Content;
use App\Models\Location;
use App\Models\PageIndexState;
use App\Models\Site;
use App\Models\User;
use App\Operator\ActiveTenant;
use App\Operator\Coverage\StuckPages;
use App\Publishing\Links\LinkInjector;
use Filament\Facades\Filament;
use Livewire\Livewire;

/**
 * The 30 / 60 / 90-day timeline for a page Google has NOT crawled: under 30 it is waiting (not stuck);
 * 30–60 ask Google (link, sitemap, request indexing); 60–90 decide; 90+ the site's crawl budget. Nothing
 * on it is a content lever — a page Google has not read is never rewritten.
 */
function timelineSite(): array
{
    $site = Site::factory()->create(['brand_name' => 'TL', 'domain_url' => 'https://tl.example', 'gsc_property' => 'sc-domain:tl.example']);
    $office = Location::factory()->create(['site_id' => $site->id, 'name' => 'Hackettstown office', 'served_towns' => []]);
    $hub = Content::factory()->create([
        'site_id' => $site->id, 'kind' => ContentKind::Page, 'page_type' => PageType::Location, 'status' => ContentStatus::Published,
        'location_id' => $office->id, 'title' => 'Hackettstown office', 'slug' => 'hackettstown-nj', 'wp_post_id' => 1, 'published_at' => now()->subDays(120),
        'slot_payload' => ['intro' => 'Our office covers the whole county.'],
    ]);
    PageIndexState::create(['site_id' => $site->id, 'content_id' => $hub->id, 'url' => 'https://tl.example/hackettstown-nj/', 'url_normalized' => 'https://tl.example/hackettstown-nj', 'coverage_state' => 'indexed', 'index_verdict' => 'PASS', 'indexed_at' => now()->subDays(100), 'last_inspected_at' => now()->subDay()]);
    $town = function (string $name, string $slug, int $days) use ($site, $office, $hub): Content {
        $page = Content::factory()->create([
            'site_id' => $site->id, 'kind' => ContentKind::Page, 'page_type' => PageType::Location, 'status' => ContentStatus::Published,
            'location_id' => null, 'parent_location_id' => $office->id, 'title' => $name, 'slug' => $slug, 'wp_post_id' => 2, 'published_at' => now()->subDays($days),
        ]);
        PageIndexState::create(['site_id' => $site->id, 'content_id' => $page->id, 'url' => "https://tl.example/{$slug}/", 'url_normalized' => "https://tl.example/{$slug}", 'coverage_state' => IndexCoverageState::DiscoveredNotIndexed->value, 'index_verdict' => IndexCoverageState::DiscoveredNotIndexed->value, 'last_inspected_at' => now()->subDay()]);
        app(LinkInjector::class)->appendRelated($hub->fresh(), $name, '/'.$slug);   // linked from the indexed hub

        return $page;
    };

    return [
        'site' => $site,
        'waiting' => $town('Allamuchy, NJ', 'allamuchy-nj', 20),
        'ask' => $town('Washington, NJ', 'washington-nj', 35),
        'decide' => $town('Independence, NJ', 'independence-nj', 65),
        'budget' => $town('Hope, NJ', 'hope-nj', 95),
    ];
}

it('places a discovered page on the timeline: waiting under 30 days, then ask, decide, budget — with the Search Console inspect link', function () {
    $f = timelineSite();

    $report = app(StuckPages::class)->for($f['site']);
    $rows = collect($report['rows'])->keyBy('title');

    expect($report['stuck_days'])->toBe(30)
        ->and($rows->keys()->all())->not->toContain('Allamuchy, NJ')   // 20 days: waiting, not stuck
        ->and($rows['Washington, NJ']['stage'])->toBe(StuckPages::STAGE_ASK)
        ->and($rows['Washington, NJ']['lever'])->toBe(StuckPages::REQUEST)
        ->and($rows['Washington, NJ']['action'])->toContain('request indexing')->toContain('Do not rewrite it')
        ->and($rows['Washington, NJ']['stage_label'])->toBe('30–60 days — ask Google')
        ->and($rows['Washington, NJ']['inspect_url'])->toBe('https://search.google.com/search-console/inspect?resource_id=sc-domain%3Atl.example&id=https%3A%2F%2Ftl.example%2Fwashington-nj%2F')
        ->and($rows['Independence, NJ']['stage'])->toBe(StuckPages::STAGE_DECIDE)
        ->and($rows['Independence, NJ']['lever'])->toBe(StuckPages::DECIDE)
        ->and($rows['Independence, NJ']['action'])->toContain('A town page is never dropped')
        ->and($rows['Hope, NJ']['stage'])->toBe(StuckPages::STAGE_BUDGET)
        ->and($rows['Hope, NJ']['lever'])->toBe(StuckPages::BUDGET)
        ->and($rows['Hope, NJ']['recommendation'])->toBe(StuckPages::WAIT);
});

it('the board shows the timeline legend and, on a 30–60 day page, the Search Console inspect link', function () {
    Filament::setCurrentPanel('admin');
    $this->actingAs(User::factory()->create(['role' => UserRole::Operator]));
    $f = timelineSite();
    app(ActiveTenant::class)->set($f['site']->id);

    $html = Livewire::test(IndexingBoard::class)->call('explain', $f['ask']->id)->html();

    expect($html)
        ->toContain('Not indexed, over 30 days')
        ->toContain('30–60 · ask Google')
        ->toContain('60–90 · decide')
        ->toContain('90+ · crawl budget')
        ->toContain('Inspect in Search Console')
        ->toContain('30–60 days — ask Google');
});
