<?php

use App\Jobs\PublishRedirects;
use App\Models\Redirect;
use App\Models\Scopes\SiteScope;
use App\Models\Site;
use App\Publishing\PublishRedirectsService;
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

it('pushes immediately with --now instead of queueing', function () {
    Queue::fake();
    $site = Site::factory()->create(['brand_name' => 'SPG']);

    // The push is one HTTP call to the plugin. On the console there is no FPM clock, and queueing it puts
    // that call behind whatever else is on `default` — twenty-six page generations, on the real site.
    $this->mock(PublishRedirectsService::class)
        ->shouldReceive('publish')
        ->once()
        ->withArgs(fn (Site $s): bool => $s->id === $site->id)
        ->andReturn(['ok' => true]);

    $this->artisan('launchpad:fix-redirect', [
        '--site' => 'SPG', '--from' => '/services/old', '--to' => '/new', '--apply' => true, '--now' => true,
    ])
        ->expectsOutputToContain('Pushed to WordPress now.')
        ->assertSuccessful();

    Queue::assertNothingPushed();
});

it('keeps the row and fails loudly when the immediate push cannot reach the site', function () {
    $site = Site::factory()->create(['brand_name' => 'SPG']);

    $this->mock(PublishRedirectsService::class)
        ->shouldReceive('publish')
        ->once()
        ->andThrow(new RuntimeException('401 from /redirects'));

    $this->artisan('launchpad:fix-redirect', [
        '--site' => 'SPG', '--from' => '/services/old', '--to' => '/new', '--apply' => true, '--now' => true,
    ])
        ->expectsOutputToContain('Push to WordPress failed: 401 from /redirects')
        ->assertFailed();

    // A failed push must not lose the fix — the control-plane row is the record, the push is the delivery.
    expect(Redirect::withoutGlobalScope(SiteScope::class)->where('site_id', $site->id)->where('from_url', '/services/old')->value('to_url'))
        ->toBe('/new');
});

it('still queues with --push and says queued is not live', function () {
    Queue::fake();
    Site::factory()->create(['brand_name' => 'SPG']);

    $this->artisan('launchpad:fix-redirect', [
        '--site' => 'SPG', '--from' => '/services/old', '--to' => '/new', '--apply' => true, '--push' => true,
    ])
        ->expectsOutputToContain('Queued is not live')
        ->assertSuccessful();

    Queue::assertPushed(PublishRedirects::class);
});
