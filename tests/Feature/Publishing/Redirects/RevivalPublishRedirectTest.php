<?php

use App\Models\Redirect;
use App\Models\Scopes\SiteScope;
use App\Publishing\PublishContentService;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Tests\Support\PublishHarness;

it('301s every old URL in a revived family to the new post on publish', function () {
    Queue::fake();
    PublishHarness::fakeAdapters();
    Http::fake(['*/wp-json/launchpad/v1/content' => Http::response(['wp_post_id' => 321, 'status' => 'publish', 'skipped' => false], 200)]);

    $site = PublishHarness::site(); // domain_url https://apex.example
    $content = PublishHarness::approvedPage($site); // slug water-heater-repair-austin
    $content->forceFill(['meta' => array_merge($content->meta ?? [], [
        'revived_from_urls' => ['/old-water-heater-cost-guide', '/old-water-heater-cost-guide-2'],
    ])])->save();

    app(PublishContentService::class)->publish($content, 'operator-1');

    $rows = Redirect::withoutGlobalScope(SiteScope::class)->where('site_id', $site->id)->get()->keyBy('from_url');
    expect($rows)->toHaveCount(2)
        ->and($rows['/old-water-heater-cost-guide']->to_url)->toBe('/water-heater-repair-austin')
        ->and((int) $rows['/old-water-heater-cost-guide']->code)->toBe(301)
        ->and($rows['/old-water-heater-cost-guide-2']->to_url)->toBe('/water-heater-repair-austin');
});

it('honors the legacy single-URL revived_from_url shape', function () {
    Queue::fake();
    PublishHarness::fakeAdapters();
    Http::fake(['*/wp-json/launchpad/v1/content' => Http::response(['wp_post_id' => 324, 'status' => 'publish', 'skipped' => false], 200)]);

    $site = PublishHarness::site();
    $content = PublishHarness::approvedPage($site);
    $content->forceFill(['meta' => array_merge($content->meta ?? [], ['revived_from_url' => '/legacy-single'])])->save();

    app(PublishContentService::class)->publish($content, 'operator-1');

    expect(Redirect::withoutGlobalScope(SiteScope::class)->where('site_id', $site->id)->where('from_url', '/legacy-single')->exists())->toBeTrue();
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
