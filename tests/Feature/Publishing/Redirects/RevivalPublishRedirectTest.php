<?php

use App\Models\Redirect;
use App\Models\Scopes\SiteScope;
use App\Publishing\PublishContentService;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Tests\Support\PublishHarness;

it('301s the old URL to the new post when a revived content publishes', function () {
    Queue::fake();
    PublishHarness::fakeAdapters();
    Http::fake(['*/wp-json/launchpad/v1/content' => Http::response(['wp_post_id' => 321, 'status' => 'publish', 'skipped' => false], 200)]);

    $site = PublishHarness::site(); // domain_url https://apex.example
    $content = PublishHarness::approvedPage($site); // slug water-heater-repair-austin
    $content->forceFill(['meta' => array_merge($content->meta ?? [], ['revived_from_url' => '/old-water-heater-cost-guide'])])->save();

    app(PublishContentService::class)->publish($content, 'operator-1');

    $redirect = Redirect::withoutGlobalScope(SiteScope::class)
        ->where('site_id', $site->id)
        ->where('from_url', '/old-water-heater-cost-guide')
        ->first();

    expect($redirect)->not->toBeNull()
        ->and($redirect->to_url)->toBe('/water-heater-repair-austin')
        ->and((int) $redirect->code)->toBe(301);
});

it('writes no redirect for ordinary (non-revived) content', function () {
    Queue::fake();
    PublishHarness::fakeAdapters();
    Http::fake(['*/wp-json/launchpad/v1/content' => Http::response(['wp_post_id' => 322, 'status' => 'publish', 'skipped' => false], 200)]);

    $site = PublishHarness::site();
    $content = PublishHarness::approvedPage($site);

    app(PublishContentService::class)->publish($content, 'operator-1');

    expect(Redirect::withoutGlobalScope(SiteScope::class)->where('site_id', $site->id)->count())->toBe(0);
});
