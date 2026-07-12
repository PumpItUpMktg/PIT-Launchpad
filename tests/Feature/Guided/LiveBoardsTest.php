<?php

use App\Enums\BeatabilityLane;
use App\Enums\ContentKind;
use App\Enums\ContentStatus;
use App\Enums\KeywordSource;
use App\Enums\PageType;
use App\Guided\GrowDashboard;
use App\Guided\LiveBoards;
use App\Integrations\SearchConsole\NullSearchConsole;
use App\Integrations\SearchConsole\PageSearchStats;
use App\Integrations\SearchConsole\SearchConsoleProvider;
use App\Locations\TownLocationAssigner;
use App\Models\Content;
use App\Models\Keyword;
use App\Models\Location;
use App\Models\PositionSnapshot;
use App\Models\Service;
use App\Models\Site;

function lbSite(): Site
{
    return Site::factory()->create(['domain_url' => 'https://spg.example', 'brand_name' => 'SPG']);
}

function lbLocation(Site $site, string $city, string $state, array $towns, array $overrides = []): Location
{
    return Location::factory()->create(array_merge([
        'site_id' => $site->id,
        'name' => $city.' office',
        'address_components' => [
            ['types' => ['locality'], 'long_name' => $city, 'short_name' => $city],
            ['types' => ['administrative_area_level_1'], 'long_name' => $state, 'short_name' => $state],
        ],
        'served_towns' => array_map(fn (string $t): array => ['name' => $t, 'state' => $state, 'lat' => null, 'lng' => null, 'geocoded' => false], $towns),
    ], $overrides));
}

function lbPublished(Site $site, array $overrides = []): Content
{
    return Content::factory()->create(array_merge([
        'site_id' => $site->id,
        'kind' => ContentKind::Page,
        'status' => ContentStatus::Published,
        'published_at' => now()->subDays(10),
    ], $overrides));
}

it('assigns town pages to the location whose served_towns claim them — orphans stay for the picker', function () {
    $site = lbSite();
    $trooper = lbLocation($site, 'Trooper', 'PA', ['Norristown', 'Audubon']);
    lbLocation($site, 'Montclair', 'NJ', ['Clifton']);

    $norristown = lbPublished($site, ['page_type' => PageType::Location, 'title' => 'Norristown, PA', 'slug' => 'norristown-pa']);
    $clifton = lbPublished($site, ['page_type' => PageType::Location, 'title' => 'Clifton', 'slug' => 'clifton-nj']);
    $mystery = lbPublished($site, ['page_type' => PageType::Location, 'title' => 'Doylestown', 'slug' => 'doylestown-pa']);

    $result = app(TownLocationAssigner::class)->assign($site);

    expect($result['assigned'])->toBe(2)
        ->and($result['unmatched'])->toBe(['Doylestown'])
        ->and($norristown->fresh()->parent_location_id)->toBe($trooper->id)
        ->and($clifton->fresh()->parent_location_id)->not->toBe($trooper->id)
        ->and($mystery->fresh()->parent_location_id)->toBeNull()
        // The assigner NEVER touches location_id — the composeLocation pin is a different concept.
        ->and($norristown->fresh()->location_id)->toBeNull();
});

it('a single-location site assigns every town page to its only location — nothing to disambiguate', function () {
    $site = lbSite();
    $only = lbLocation($site, 'Trooper', 'PA', []); // no served_towns captured at all

    $town = lbPublished($site, ['page_type' => PageType::Location, 'title' => 'Eagleville', 'slug' => 'eagleville-pa']);

    $result = app(TownLocationAssigner::class)->assign($site);

    expect($result['assigned'])->toBe(1)
        ->and($town->fresh()->parent_location_id)->toBe($only->id);
});

it('groups the Locations board: landing card, assigned towns, city-service pages, and orphans', function () {
    $site = lbSite();
    $trooper = lbLocation($site, 'Trooper', 'PA', ['Norristown']);

    // The location LANDING page (composeLocation pin).
    $landing = lbPublished($site, ['page_type' => PageType::Location, 'title' => 'Trooper, PA', 'slug' => 'trooper-pa', 'location_id' => $trooper->id]);
    // An assigned town page + an earned city-service page + an orphan.
    $town = lbPublished($site, ['page_type' => PageType::Location, 'title' => 'Norristown', 'slug' => 'norristown-pa', 'parent_location_id' => $trooper->id]);
    $service = Service::factory()->create(['site_id' => $site->id]);
    $pair = lbPublished($site, ['page_type' => PageType::Location, 'title' => 'Sump Pump Installation in Norristown', 'slug' => 'norristown-sump-pump-installation', 'parent_location_id' => $trooper->id, 'primary_service_id' => $service->id]);
    $orphan = lbPublished($site, ['page_type' => PageType::Location, 'title' => 'Doylestown', 'slug' => 'doylestown-pa']);
    // An UNPUBLISHED town never reaches the board.
    Content::factory()->create(['site_id' => $site->id, 'kind' => ContentKind::Page, 'page_type' => PageType::Location, 'title' => 'Audubon', 'slug' => 'audubon-pa', 'parent_location_id' => $trooper->id, 'status' => ContentStatus::NeedsReview]);

    $board = app(LiveBoards::class)->locations($site);

    expect($board['groups'])->toHaveCount(1);
    $group = $board['groups'][0];
    expect($group['location_card']['id'])->toBe($landing->id)
        ->and(collect($group['towns'])->pluck('id')->all())->toBe([$town->id])
        ->and(collect($group['city_services'])->pluck('id')->all())->toBe([$pair->id])
        ->and($group['rollup']['towns_live'])->toBe(1)
        ->and(collect($board['orphans'])->pluck('id')->all())->toBe([$orphan->id])
        ->and($group['towns'][0]['url'])->toBe('https://spg.example/norristown-pa');
});

it('resolves the position block from the snapshot series with an honest delta and pendings', function () {
    $site = lbSite();
    $keyword = Keyword::create(['site_id' => $site->id, 'query' => 'sump pump installation', 'source' => KeywordSource::Seed, 'status' => 'candidate']);
    $page = lbPublished($site, ['page_type' => PageType::Service, 'title' => 'Sump Pump Installation', 'slug' => 'sump-pump-installation', 'target_keyword_id' => $keyword->id]);

    PositionSnapshot::factory()->create(['site_id' => $site->id, 'keyword_id' => $keyword->id, 'lane' => BeatabilityLane::Organic, 'rank' => 12, 'captured_at' => now()->subDays(28)]);
    PositionSnapshot::factory()->create(['site_id' => $site->id, 'keyword_id' => $keyword->id, 'lane' => BeatabilityLane::Organic, 'rank' => 6, 'captured_at' => now()->subDay()]);

    $cards = app(LiveBoards::class)->services($site);
    $m = $cards[0]['metrics'];

    expect($m['position']['rank'])->toBe(6)
        ->and($m['position']['delta'])->toBe(6)          // 12 → 6: improved by 6
        ->and($m['keyword'])->toBe('sump pump installation')
        ->and(count($m['series']))->toBe(2)
        // Null providers → connect prompts, never zeros.
        ->and($m['gsc']['impressions'])->toBeNull()
        ->and($m['gsc']['pending'])->toBe('Connect Search Console')
        ->and($m['traffic']['pending'])->toBe('Connect GA4');

    // A keyword-less core page explains WHY position is empty.
    $about = lbPublished($site, ['page_type' => PageType::Utility, 'title' => 'About', 'slug' => 'about']);
    $core = app(LiveBoards::class)->core($site);
    expect(collect($core)->firstWhere('id', $about->id)['metrics']['position']['pending'])->toBe('No target keyword — brand page');
});

it('renders Search Console numbers once the provider connects (and the rollup sums them)', function () {
    $site = lbSite();
    $trooper = lbLocation($site, 'Trooper', 'PA', ['Norristown', 'Audubon']);
    lbPublished($site, ['page_type' => PageType::Location, 'title' => 'Norristown', 'slug' => 'norristown-pa', 'parent_location_id' => $trooper->id]);
    lbPublished($site, ['page_type' => PageType::Location, 'title' => 'Audubon', 'slug' => 'audubon-pa', 'parent_location_id' => $trooper->id]);

    app()->instance(SearchConsoleProvider::class, new class implements SearchConsoleProvider
    {
        public function connected(Site $site): bool
        {
            return true;
        }

        public function pageStats(Site $site, string $path, int $days = 28): ?PageSearchStats
        {
            return new PageSearchStats(impressions: 300, clicks: 12, days: $days);
        }
    });

    $board = app(LiveBoards::class)->locations($site);
    $group = $board['groups'][0];

    expect($group['towns'][0]['metrics']['gsc']['impressions'])->toBe(300)
        ->and($group['towns'][0]['metrics']['gsc']['ctr'])->toBe(4.0)
        ->and($group['rollup']['impressions'])->toBe(600)
        ->and($group['rollup']['clicks'])->toBe(24);
});

it('published pages leave the Grow work board but keep counting in its stats', function () {
    $site = lbSite();
    $live = lbPublished($site, ['page_type' => PageType::Service, 'title' => 'Sump Pump Installation', 'slug' => 'sump-pump-installation']);
    $working = Content::factory()->create(['site_id' => $site->id, 'kind' => ContentKind::Page, 'page_type' => PageType::Service, 'title' => 'French Drains', 'slug' => 'french-drains', 'status' => ContentStatus::NeedsReview, 'body' => null, 'slot_payload' => ['svc_intro' => 'x']]);

    $dashboard = app(GrowDashboard::class);

    $ids = collect($dashboard->pages($site))->pluck('id');
    expect($ids)->not->toContain($live->id)
        ->and($ids)->toContain($working->id);

    // The header stats read the FULL set — live stays counted.
    expect($dashboard->stats($site)['live'])->toBe(1);

    // The null-provider default keeps the Search Console seam honest for every other test.
    expect(app(SearchConsoleProvider::class))->toBeInstanceOf(NullSearchConsole::class);
});
