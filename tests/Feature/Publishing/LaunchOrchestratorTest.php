<?php

use App\Enums\ConnectionProvider;
use App\Enums\ContentStatus;
use App\Enums\LaunchRunStatus;
use App\Models\Connection;
use App\Models\Content;
use App\Models\LaunchRun;
use App\Models\Redirect;
use App\Models\Scopes\SiteScope;
use App\Models\Silo;
use App\Models\Site;
use App\Publishing\LaunchOrchestrator;
use Illuminate\Support\Facades\Http;

function apexSite(): Site
{
    $site = Site::factory()->create(['brand_name' => 'Apex Services']);
    Connection::factory()->rotated()->create([
        'site_id' => $site->id,
        'provider' => ConnectionProvider::WpAppPassword->value,
        'credentials' => ['base_url' => 'https://apex.test', 'username' => 'launchpad-sync', 'app_password' => 'app pass'],
    ]);

    return $site;
}

function fakePluginOk(): void
{
    Http::fake([
        '*/launchpad/v1/silo' => Http::response(['wp_category_id' => 7]),
        '*/launchpad/v1/content' => Http::response(['wp_post_id' => 42, 'status' => 'publish', 'skipped' => false]),
        '*/launchpad/v1/redirects' => Http::response(['count' => 2]),
    ]);
}

function launchRuns(string $siteId)
{
    return LaunchRun::withoutGlobalScope(SiteScope::class)->where('site_id', $siteId);
}

it('pushes silos → content → redirects in order and records the run with WP ids', function () {
    $site = apexSite();
    $pillar = Silo::factory()->create(['site_id' => $site->id, 'name' => 'Plumbing']);
    Content::factory()->page()->create(['site_id' => $site->id, 'silo_id' => $pillar->id, 'status' => ContentStatus::Approved, 'title' => 'Water Heater Repair']);
    Redirect::factory()->create(['site_id' => $site->id, 'status' => 'active']);
    fakePluginOk();

    $run = app(LaunchOrchestrator::class)->launch($site);

    expect($run->status)->toBe(LaunchRunStatus::Completed)
        ->and($run->pushed)->toBe(3)   // 1 silo + 1 content + 1 redirects
        ->and($run->failed)->toBe(0)
        ->and(array_map(fn ($i) => $i['kind'], $run->items))->toBe(['silo', 'content', 'redirects']);

    expect(collect($run->items)->firstWhere('kind', 'silo')['wp_id'])->toBe(7)
        ->and(collect($run->items)->firstWhere('kind', 'content')['wp_id'])->toBe(42);
});

it('records a plugin {skipped:true} as skipped — never re-pushed or clobbered', function () {
    $site = apexSite();
    Content::factory()->page()->create(['site_id' => $site->id, 'status' => ContentStatus::Approved, 'title' => 'Edited In WP']);
    Http::fake([
        '*/launchpad/v1/content' => Http::response(['wp_post_id' => 0, 'status' => 'draft', 'skipped' => true]),
        '*/launchpad/v1/redirects' => Http::response(['count' => 0]),
    ]);

    $run = app(LaunchOrchestrator::class)->launch($site);

    expect($run->skipped)->toBe(1)
        ->and(collect($run->items)->firstWhere('kind', 'content')['state'])->toBe('skipped');

    // The publisher flags it locally-edited so a later push keeps skipping it.
    $content = Content::withoutGlobalScope(SiteScope::class)->where('site_id', $site->id)->firstOrFail();
    expect($content->locally_edited)->toBeTrue();
});

it('isolates a single page failure — records it and continues the launch', function () {
    $site = apexSite();
    Content::factory()->page()->create(['site_id' => $site->id, 'status' => ContentStatus::Approved, 'title' => 'Page A', 'slug' => 'page-a']);
    Content::factory()->post()->create(['site_id' => $site->id, 'status' => ContentStatus::Approved, 'title' => 'Post B', 'slug' => 'post-b']);
    Http::fake([
        // Page A (pushed first): 3 attempts all 5xx → fail. Post B: success.
        '*/launchpad/v1/content' => Http::sequence()
            ->push('boom', 500)->push('boom', 500)->push('boom', 500)
            ->push(['wp_post_id' => 99, 'status' => 'publish', 'skipped' => false]),
        '*/launchpad/v1/redirects' => Http::response(['count' => 0]),
    ]);

    $run = app(LaunchOrchestrator::class)->launch($site);

    expect($run->status)->toBe(LaunchRunStatus::Completed) // a failure never aborts the run
        ->and($run->failed)->toBe(1)
        ->and($run->pushed)->toBe(2)                        // Post B + redirects
        ->and(collect($run->items)->firstWhere('kind', 'redirects')['state'])->toBe('pushed');
});

it('refuses to launch without a present, non-compromised WordPress connection', function () {
    $site = Site::factory()->create();
    Connection::factory()->compromised()->create(['site_id' => $site->id, 'provider' => ConnectionProvider::WpAppPassword->value]);
    Http::fake();

    $run = app(LaunchOrchestrator::class)->launch($site);

    expect($run->status)->toBe(LaunchRunStatus::Blocked);
    Http::assertNothingSent();
});

it('refuses to launch when there is no WordPress connection at all', function () {
    $site = Site::factory()->create();
    Http::fake();

    expect(app(LaunchOrchestrator::class)->launch($site)->status)->toBe(LaunchRunStatus::Blocked);
    Http::assertNothingSent();
});

it('is re-launchable — a second run completes and re-pushes safely', function () {
    $site = apexSite();
    Content::factory()->page()->create(['site_id' => $site->id, 'status' => ContentStatus::Approved]);
    fakePluginOk();

    $first = app(LaunchOrchestrator::class)->launch($site);
    $second = app(LaunchOrchestrator::class)->launch($site);

    expect($first->status)->toBe(LaunchRunStatus::Completed)
        ->and($second->status)->toBe(LaunchRunStatus::Completed)
        ->and(launchRuns($site->id)->count())->toBe(2);
});

it('launchpad:launch-site pushes a site and reports the run', function () {
    $site = apexSite();
    Content::factory()->page()->create(['site_id' => $site->id, 'status' => ContentStatus::Approved]);
    fakePluginOk();

    $this->artisan('launchpad:launch-site', ['site' => 'Apex Services'])
        ->assertSuccessful()
        ->expectsOutputToContain('Launched Apex Services');
});

it('launchpad:launch-site fails (and pushes nothing) when the connection is compromised', function () {
    $site = Site::factory()->create(['brand_name' => 'Blocked Co']);
    Connection::factory()->compromised()->create(['site_id' => $site->id, 'provider' => ConnectionProvider::WpAppPassword->value]);
    Http::fake();

    $this->artisan('launchpad:launch-site', ['site' => 'Blocked Co'])->assertFailed();
    Http::assertNothingSent();
});
