<?php

use App\Jobs\PublishRedirects;
use App\Models\Redirect;
use App\Models\Scopes\SiteScope;
use App\Models\Site;
use Illuminate\Support\Facades\Queue;

it('previews a repoint without writing', function () {
    $site = Site::factory()->create(['brand_name' => 'SPG']);

    $this->artisan('launchpad:fix-redirect', ['--site' => 'SPG', '--from' => '/hoboken/', '--to' => '/hoboken-nj'])
        ->expectsOutputToContain('Preview only')
        ->assertSuccessful();

    expect(Redirect::withoutGlobalScope(SiteScope::class)->where('site_id', $site->id)->count())->toBe(0);
});

it('repoints (upserts) a redirect on apply, normalizing the paths', function () {
    $site = Site::factory()->create(['brand_name' => 'SPG']);

    $this->artisan('launchpad:fix-redirect', ['--site' => 'SPG', '--from' => 'hoboken/', '--to' => 'hoboken-nj', '--apply' => true])
        ->assertSuccessful();

    $r = Redirect::withoutGlobalScope(SiteScope::class)->where('site_id', $site->id)->first();
    expect($r)->not->toBeNull()
        ->and($r->from_url)->toBe('/hoboken/')
        ->and($r->to_url)->toBe('/hoboken-nj')
        ->and($r->code)->toBe(301)
        ->and($r->status)->toBe('active');
});

it('overwrites an existing stale target', function () {
    $site = Site::factory()->create(['brand_name' => 'SPG']);
    Redirect::withoutGlobalScope(SiteScope::class)->create([
        'site_id' => $site->id, 'from_url' => '/hoboken/', 'to_url' => '/blog/hoboken-flood', 'code' => 301, 'status' => 'active',
    ]);

    $this->artisan('launchpad:fix-redirect', ['--site' => 'SPG', '--from' => '/hoboken/', '--to' => '/hoboken-nj', '--apply' => true])
        ->assertSuccessful();

    expect(Redirect::withoutGlobalScope(SiteScope::class)->where('site_id', $site->id)->where('from_url', '/hoboken/')->value('to_url'))
        ->toBe('/hoboken-nj');
});

it('queues the WP push with --push', function () {
    Queue::fake();
    $site = Site::factory()->create(['brand_name' => 'SPG']);

    $this->artisan('launchpad:fix-redirect', ['--site' => 'SPG', '--from' => '/hoboken/', '--to' => '/hoboken-nj', '--apply' => true, '--push' => true])
        ->assertSuccessful();

    Queue::assertPushed(PublishRedirects::class, 1);
});

it('deactivates a redirect with --delete', function () {
    $site = Site::factory()->create(['brand_name' => 'SPG']);
    Redirect::withoutGlobalScope(SiteScope::class)->create([
        'site_id' => $site->id, 'from_url' => '/hoboken/', 'to_url' => '/blog/x', 'code' => 301, 'status' => 'active',
    ]);

    $this->artisan('launchpad:fix-redirect', ['--site' => 'SPG', '--from' => '/hoboken/', '--delete' => true, '--apply' => true])
        ->assertSuccessful();

    expect(Redirect::withoutGlobalScope(SiteScope::class)->where('site_id', $site->id)->value('status'))->toBe('inactive');
});

it('requires --site, --from, and --to', function () {
    Site::factory()->create(['brand_name' => 'SPG']);
    $this->artisan('launchpad:fix-redirect', ['--from' => '/x/', '--to' => '/y'])->assertFailed();
    $this->artisan('launchpad:fix-redirect', ['--site' => 'SPG', '--to' => '/y'])->assertFailed();
    $this->artisan('launchpad:fix-redirect', ['--site' => 'SPG', '--from' => '/x/'])->assertFailed();
});

it('flushes a legacy URL with --gone (410 Gone, no --to required)', function () {
    $site = Site::factory()->create(['brand_name' => 'SPG']);

    // §8.2: an out-of-footprint or dead legacy URL (e.g. /about-us/) is retired from the index.
    $this->artisan('launchpad:fix-redirect', ['--site' => 'SPG', '--from' => '/about-us/', '--gone' => true, '--apply' => true])
        ->assertSuccessful();

    $r = Redirect::withoutGlobalScope(SiteScope::class)->where('site_id', $site->id)->where('from_url', '/about-us/')->first();
    expect($r)->not->toBeNull()
        ->and($r->code)->toBe(410)
        ->and($r->to_url)->toBe('')       // no destination — the plugin emits 410 and stops
        ->and($r->status)->toBe('active');
});

it('the footprint config is the single territory source (six marketed + planned states)', function () {
    // §8.2: in-footprint = PA/NJ/MD (marketed) + NY/CT/DE (planned) → parked, never 410'd.
    expect(config('launchpad.footprint.states'))->toBe(['PA', 'NJ', 'MD', 'NY', 'CT', 'DE']);
});
