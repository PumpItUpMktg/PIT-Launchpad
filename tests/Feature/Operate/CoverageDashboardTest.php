<?php

use App\Analytics\Gsc\Grain;
use App\Enums\ContentKind;
use App\Enums\ContentStatus;
use App\Enums\PageType;
use App\Models\Content;
use App\Models\Job;
use App\Models\Location;
use App\Models\Site;
use App\Operate\CoverageDashboard;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

function gscRow(Site $site, string $slug, int $impressions, int $clicks = 0): void
{
    $date = now()->subDays(2)->toDateString();
    $url = 'https://apex.example/'.trim($slug, '/').'/';
    DB::table('gsc_url_daily')->insert([
        'id' => (string) Str::ulid(),
        'site_id' => $site->id,
        'grain_hash' => Grain::hash([$site->id, $date, $url]),
        'date' => $date,
        'url' => $url,
        'impressions' => $impressions,
        'clicks' => $clicks,
        'ctr' => 0,
        'position' => 6,
        'created_at' => now(),
        'updated_at' => now(),
    ]);
}

function dashGroup(array $dash, string $label): ?array
{
    return collect($dash['groups'])->firstWhere('label', $label);
}

it('buckets live URLs by type, counts indexed via the impressions proxy, and orders most-visible-first', function () {
    $site = Site::factory()->create(['domain_url' => 'https://apex.example']);
    $storefront = Location::factory()->create(['site_id' => $site->id, 'is_storefront' => true]);

    $service = Content::factory()->create(['site_id' => $site->id, 'kind' => ContentKind::Page, 'page_type' => PageType::Service, 'status' => ContentStatus::Published, 'wp_post_id' => 1, 'slug' => 'sump-pump-repair', 'title' => 'Sump Pump Repair']);
    $core = Content::factory()->create(['site_id' => $site->id, 'kind' => ContentKind::Page, 'page_type' => PageType::Home, 'status' => ContentStatus::Published, 'wp_post_id' => 2, 'slug' => 'home', 'title' => 'Home']);
    Content::factory()->create(['site_id' => $site->id, 'kind' => ContentKind::Page, 'page_type' => PageType::Location, 'status' => ContentStatus::Published, 'wp_post_id' => 3, 'slug' => 'hoboken', 'title' => 'Hoboken', 'location_id' => $storefront->id]);
    Content::factory()->create(['site_id' => $site->id, 'kind' => ContentKind::Page, 'page_type' => PageType::Location, 'status' => ContentStatus::Published, 'wp_post_id' => 4, 'slug' => 'hoboken/downtown', 'title' => 'Downtown Hoboken', 'parent_location_id' => $storefront->id]);
    Content::factory()->post()->create(['site_id' => $site->id, 'status' => ContentStatus::Published, 'wp_post_id' => 5, 'slug' => 'a-post', 'title' => 'A Post']);
    Job::factory()->published()->create(['site_id' => $site->id, 'post_title' => 'Sump Pump Replacement']);

    // Service page is the most visible; core has some; the rest have none.
    gscRow($site, 'sump-pump-repair', 500, 10);
    gscRow($site, 'home', 120, 3);

    $dash = app(CoverageDashboard::class)->forSite($site);

    // Grouping + the storefront vs town split.
    expect(collect($dash['groups'])->pluck('label'))
        ->toContain('Service', 'Core', 'Brick & Mortar', 'Location / Town', 'Blog', 'Jobs');

    // Indexed via impressions proxy; not-indexed = total − indexed.
    $svc = dashGroup($dash, 'Service');
    expect($svc['total'])->toBe(1)
        ->and($svc['indexed'])->toBe(1)          // 500 impressions → green
        ->and($svc['not_indexed'])->toBe(0)
        ->and($svc['impressions'])->toBe(500)
        ->and($svc['clicks'])->toBe(10);

    // Blog post has no impressions → not indexed.
    $blog = dashGroup($dash, 'Blog');
    expect($blog['total'])->toBe(1)->and($blog['indexed'])->toBe(0)->and($blog['not_indexed'])->toBe(1);

    // Ordered most-visible-first: Service (500) before Core (120) before the zero-impression groups.
    expect($dash['groups'][0]['label'])->toBe('Service')
        ->and($dash['groups'][1]['label'])->toBe('Core');

    // Portfolio totals.
    expect($dash['totals']['total'])->toBe(6)            // 5 content + 1 job
        ->and($dash['totals']['impressions'])->toBe(620)
        ->and($dash['totals']['indexed'])->toBe(2);      // service + core
});

it('drills into per-group pages sorted by impressions', function () {
    $site = Site::factory()->create(['domain_url' => 'https://apex.example']);
    Content::factory()->create(['site_id' => $site->id, 'kind' => ContentKind::Page, 'page_type' => PageType::Service, 'status' => ContentStatus::Published, 'wp_post_id' => 1, 'slug' => 'big', 'title' => 'Big']);
    Content::factory()->create(['site_id' => $site->id, 'kind' => ContentKind::Page, 'page_type' => PageType::Service, 'status' => ContentStatus::Published, 'wp_post_id' => 2, 'slug' => 'small', 'title' => 'Small']);
    gscRow($site, 'big', 300);
    gscRow($site, 'small', 10);

    $svc = dashGroup(app(CoverageDashboard::class)->forSite($site), 'Service');

    expect($svc['pages'][0]['title'])->toBe('Big')          // most impressions first
        ->and($svc['pages'][0]['pill'])->toBe('indexed')
        ->and($svc['pages'][1]['title'])->toBe('Small');
});
