<?php

use App\Enums\ConnectionProvider;
use App\Enums\ContentStatus;
use App\Enums\PageType;
use App\Models\Connection;
use App\Models\Content;
use App\Models\Site;
use App\Publishing\LinkRepublisher;
use Illuminate\Support\Facades\Http;

function linkFixSite(): Site
{
    $site = Site::factory()->create(['brand_name' => 'Link Co']);
    Connection::factory()->rotated()->create([
        'site_id' => $site->id,
        'provider' => ConnectionProvider::WpAppPassword->value,
        'credentials' => ['base_url' => 'https://link.test', 'username' => 'launchpad-sync', 'app_password' => 'app pass'],
    ]);

    return $site;
}

it('repushes only live pages, leaves-first, and leaves unpublished ones alone', function () {
    $site = linkFixSite();
    Http::fake([
        '*/launchpad/v1/silo' => Http::response(['wp_category_id' => 7]),
        '*/launchpad/v1/content' => Http::response(['wp_post_id' => 42, 'status' => 'publish', 'skipped' => false]),
    ]);

    // Two LIVE pages (published + on WordPress) …
    $home = Content::factory()->page()->create([
        'site_id' => $site->id, 'status' => ContentStatus::Published, 'wp_post_id' => 10,
        'page_type' => PageType::Home, 'title' => 'Home', 'slug' => 'home', 'slot_payload' => ['hero_headline' => 'Home'],
    ]);
    $service = Content::factory()->page()->create([
        'site_id' => $site->id, 'status' => ContentStatus::Published, 'wp_post_id' => 11,
        'page_type' => PageType::Service, 'title' => 'Service A', 'slug' => 'service-a', 'slot_payload' => ['hero_headline' => 'Service'],
    ]);
    // … and one NOT on WordPress yet — must not be touched.
    Content::factory()->page()->create([
        'site_id' => $site->id, 'status' => ContentStatus::Candidate, 'wp_post_id' => null,
        'page_type' => PageType::Service, 'title' => 'Draft', 'slug' => 'draft',
    ]);

    $result = app(LinkRepublisher::class)->republish($site);

    expect($result['total'])->toBe(2)       // only the two live pages
        ->and($result['repushed'])->toBe(2)
        ->and($result['failed'])->toBe(0);

    // Leaves-first: the service spoke recomposes BEFORE Home, so Home's grid sees it live.
    $order = [];
    Http::recorded(function ($request) use (&$order) {
        if (str_contains($request->url(), '/launchpad/v1/content')) {
            $order[] = (string) ($request['content_id'] ?? '');
        }
    });
    expect(array_search($service->id, $order))->toBeLessThan(array_search($home->id, $order));
});

it('reports nothing to fix when the site has no live pages', function () {
    $site = linkFixSite();
    Http::fake();

    $result = app(LinkRepublisher::class)->republish($site);

    expect($result)->toMatchArray(['repushed' => 0, 'skipped' => 0, 'failed' => 0, 'total' => 0]);
    Http::assertNothingSent();
});
