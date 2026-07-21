<?php

use App\Enums\ConnectionProvider;
use App\Enums\ContentKind;
use App\Enums\ContentStatus;
use App\Models\Connection;
use App\Models\Content;
use App\Models\Scopes\SiteScope;
use App\Models\Site;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Http;

function drainSite(): Site
{
    $site = Site::factory()->create(['domain_url' => 'https://drain.example', 'brand_name' => 'DrainCo']);
    Connection::factory()->rotated()->create([
        'site_id' => $site->id,
        'provider' => ConnectionProvider::WpAppPassword->value,
        'credentials' => ['base_url' => 'https://drain.example', 'username' => 'launchpad-sync', 'app_password' => 'pw'],
    ]);

    return $site;
}

test('drain-publish publishes every in-flight post synchronously', function () {
    Http::fake(['*/launchpad/v1/content' => Http::response(['wp_post_id' => 55, 'status' => 'publish', 'skipped' => false])]);
    $site = drainSite();
    Content::factory()->post()->count(3)->create(['site_id' => $site->id, 'status' => ContentStatus::Approved]);
    // A published post is NOT in flight — it must be left alone.
    Content::factory()->post()->create(['site_id' => $site->id, 'status' => ContentStatus::Published]);

    $code = Artisan::call('launchpad:drain-publish', ['site' => $site->id]);

    expect($code)->toBe(0)
        ->and(Artisan::output())->toContain('3 published');
    expect(Content::withoutGlobalScope(SiteScope::class)->where('site_id', $site->id)
        ->where('kind', ContentKind::Post->value)->where('status', ContentStatus::Approved->value)->count())->toBe(0);
});

test('drain-publish --dry-run lists the stuck posts without publishing', function () {
    Http::fake();
    $site = drainSite();
    Content::factory()->post()->create(['site_id' => $site->id, 'status' => ContentStatus::Approved, 'title' => 'Stuck One']);

    Artisan::call('launchpad:drain-publish', ['site' => $site->id, '--dry-run' => true]);

    expect(Artisan::output())->toContain('Stuck One')->toContain('Dry run');
    Http::assertNothingSent();
    expect(Content::withoutGlobalScope(SiteScope::class)->where('site_id', $site->id)
        ->where('status', ContentStatus::Approved->value)->count())->toBe(1);
});

test('drain-publish reports nothing to do when no post is in flight', function () {
    $site = drainSite();

    Artisan::call('launchpad:drain-publish', ['site' => $site->brand_name]);

    expect(Artisan::output())->toContain('nothing in flight');
});
