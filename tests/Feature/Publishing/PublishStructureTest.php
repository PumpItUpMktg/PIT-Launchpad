<?php

use App\Enums\ContentKind;
use App\Enums\PageType;
use App\Models\Content;
use App\Models\Redirect;
use App\Models\Silo;
use App\Publishing\PublishRedirectsService;
use App\Publishing\PublishSiloService;
use Illuminate\Support\Facades\Http;
use Tests\Support\PublishHarness;

test('publishing a silo pushes the structure and stores the returned wp_category_id', function () {
    $site = PublishHarness::site();
    $silo = Silo::factory()->create(['site_id' => $site->id, 'name' => 'Plumbing', 'wp_category_id' => null]);

    Http::fake([
        '*/wp-json/launchpad/v1/silo' => Http::response(['silo_id' => $silo->id, 'wp_category_id' => 34], 200),
    ]);

    app(PublishSiloService::class)->publish($silo);

    expect($silo->fresh()->wp_category_id)->toBe(34);

    Http::assertSent(fn ($r) => $r['silo_id'] === $silo->id
        && $r['name'] === 'Plumbing'
        && array_key_exists('parent_silo_id', $r->data()));
});

test('the silo push carries the pillar page meta description as the category description', function () {
    $site = PublishHarness::site();
    $pillar = Content::factory()->create([
        'site_id' => $site->id, 'kind' => ContentKind::Page, 'page_type' => PageType::Hub, 'slug' => 'plumbing',
        'meta' => ['seo' => ['meta_description' => 'Everything plumbing — repairs, installs, and drain work.']],
    ]);
    $silo = Silo::factory()->create(['site_id' => $site->id, 'name' => 'Plumbing', 'pillar_content_id' => $pillar->id, 'wp_category_id' => null]);

    Http::fake(['*/wp-json/launchpad/v1/silo' => Http::response(['silo_id' => $silo->id, 'wp_category_id' => 7], 200)]);

    app(PublishSiloService::class)->publish($silo);

    Http::assertSent(fn ($r) => $r['description'] === 'Everything plumbing — repairs, installs, and drain work.');
});

test('a silo with no pillar page sends an empty description (the plugin then leaves it untouched)', function () {
    $site = PublishHarness::site();
    $silo = Silo::factory()->create(['site_id' => $site->id, 'name' => 'Plumbing', 'pillar_content_id' => null, 'wp_category_id' => null]);

    Http::fake(['*/wp-json/launchpad/v1/silo' => Http::response(['silo_id' => $silo->id, 'wp_category_id' => 8], 200)]);

    app(PublishSiloService::class)->publish($silo);

    Http::assertSent(fn ($r) => $r['description'] === '');
});

test('publishSite pushes the whole tree roots-first and stores every wp_category_id', function () {
    $site = PublishHarness::site();
    $root = Silo::factory()->create(['site_id' => $site->id, 'name' => 'Plumbing', 'parent_silo_id' => null, 'wp_category_id' => null]);
    $child = Silo::factory()->create(['site_id' => $site->id, 'name' => 'Drain Cleaning', 'parent_silo_id' => $root->id, 'wp_category_id' => null]);

    $seq = 100;
    Http::fake([
        '*/wp-json/launchpad/v1/silo' => function ($request) use (&$seq) {
            return Http::response(['silo_id' => $request['silo_id'], 'wp_category_id' => $seq++], 200);
        },
    ]);

    $count = app(PublishSiloService::class)->publishSite($site);

    expect($count)->toBe(2)
        ->and($root->fresh()->wp_category_id)->toBe(100)   // root pushed first
        ->and($child->fresh()->wp_category_id)->toBe(101); // child after

    // the root's push carried no parent; the child's named its parent
    $sent = collect(Http::recorded())->map(fn ($pair) => $pair[0]->data());
    expect($sent->first()['silo_id'])->toBe($root->id)
        ->and($sent->first()['parent_silo_id'])->toBeNull()
        ->and($sent->last()['parent_silo_id'])->toBe($root->id);
});

test('publishing redirects sends the active redirects in contract shape', function () {
    $site = PublishHarness::site();
    Redirect::factory()->create([
        'site_id' => $site->id, 'from_url' => '/old-page', 'to_url' => '/new-page', 'code' => 301, 'status' => 'active',
    ]);

    Http::fake(['*/wp-json/launchpad/v1/redirects' => Http::response(['count' => 1], 200)]);

    app(PublishRedirectsService::class)->publish($site);

    Http::assertSent(function ($r) {
        return str_contains($r->url(), '/wp-json/launchpad/v1/redirects')
            && $r['redirects'][0]['from_url'] === '/old-page'
            && $r['redirects'][0]['to_url'] === '/new-page'
            && $r['redirects'][0]['code'] === 301;
    });
});
