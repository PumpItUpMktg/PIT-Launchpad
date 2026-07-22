<?php

use App\Integrations\Wordpress\WordpressClient;
use App\Integrations\Wordpress\WordpressClientFactory;
use App\Models\Site;
use App\Models\SiteBranding;
use App\Styling\StyleVariation;

it('pushes the curated variation and reports what it applied', function () {
    $site = Site::factory()->create(['brand_name' => 'SPG', 'style_variation' => StyleVariation::Slate->value, 'use_logo_colors' => false]);

    $client = Mockery::mock(WordpressClient::class);
    $client->shouldReceive('activateStyleVariation')->once()->with('slate', Mockery::type('array'))->andReturn(['updated' => true, 'variation' => 'slate']);
    $client->shouldReceive('pushSiteProfile')->once()->andReturn(['updated' => true]);
    $factory = Mockery::mock(WordpressClientFactory::class);
    $factory->shouldReceive('forSite')->andReturn($client);
    app()->instance(WordpressClientFactory::class, $factory);

    $this->artisan('launchpad:activate-style', ['site' => $site->id])
        ->expectsOutputToContain('Resolves to: Slate & Signal')
        ->expectsOutputToContain('Applied "Slate & Signal" to WordPress global styles.')
        ->assertSuccessful();
});

it('reads back the colors WordPress actually paints after the push', function () {
    $site = Site::factory()->create(['style_variation' => StyleVariation::Slate->value, 'use_logo_colors' => false]);

    $client = Mockery::mock(WordpressClient::class);
    $client->shouldReceive('activateStyleVariation')->once()->with('slate', Mockery::type('array'))->andReturn([
        'updated' => true,
        'variation' => 'slate',
        'is_block_theme' => true,
        'active_colors' => ['primary' => '#334155', 'accent' => '#F97316', 'button' => '#F97316'],
    ]);
    $client->shouldReceive('pushSiteProfile')->andReturn(['updated' => true]);
    $factory = Mockery::mock(WordpressClientFactory::class);
    $factory->shouldReceive('forSite')->andReturn($client);
    app()->instance(WordpressClientFactory::class, $factory);

    $this->artisan('launchpad:activate-style', ['site' => $site->id])
        ->expectsOutputToContain('Applied "Slate & Signal"')
        ->expectsOutputToContain('WordPress now paints: primary #334155')
        ->expectsOutputToContain('external CDN or browser cache')
        ->assertSuccessful();
});

it('names the page caches the companion purged on the push', function () {
    $site = Site::factory()->create(['style_variation' => StyleVariation::Slate->value, 'use_logo_colors' => false]);

    $client = Mockery::mock(WordpressClient::class);
    $client->shouldReceive('activateStyleVariation')->once()->with('slate', Mockery::type('array'))->andReturn([
        'updated' => true,
        'variation' => 'slate',
        'is_block_theme' => true,
        'active_colors' => ['primary' => '#334155'],
        'page_caches_purged' => ['litespeed', 'wp-rocket'],
    ]);
    $client->shouldReceive('pushSiteProfile')->andReturn(['updated' => true]);
    $factory = Mockery::mock(WordpressClientFactory::class);
    $factory->shouldReceive('forSite')->andReturn($client);
    app()->instance(WordpressClientFactory::class, $factory);

    $this->artisan('launchpad:activate-style', ['site' => $site->id])
        ->expectsOutputToContain('Purged page cache: litespeed, wp-rocket')
        ->assertSuccessful();
});

it('warns loudly when the site is not on a block theme (the push is inert)', function () {
    $site = Site::factory()->create(['style_variation' => StyleVariation::Slate->value, 'use_logo_colors' => false]);

    $client = Mockery::mock(WordpressClient::class);
    $client->shouldReceive('activateStyleVariation')->once()->with('slate', Mockery::type('array'))->andReturn([
        'updated' => true,
        'variation' => 'slate',
        'is_block_theme' => false,
        'active_colors' => [],
    ]);
    $client->shouldReceive('pushSiteProfile')->andReturn(['updated' => true]);
    $factory = Mockery::mock(WordpressClientFactory::class);
    $factory->shouldReceive('forSite')->andReturn($client);
    app()->instance(WordpressClientFactory::class, $factory);

    $this->artisan('launchpad:activate-style', ['site' => $site->id])
        ->expectsOutputToContain('NOT running a block theme')
        ->expectsOutputToContain('activate the launchpad-blocks block theme')
        ->assertSuccessful();
});

it('the diagnostic explains when use_logo_colors overrides the curated pick', function () {
    // Slate is chosen, but use_logo_colors is still on with a usable palette — the classic drift.
    $site = Site::factory()->create(['style_variation' => StyleVariation::Slate->value, 'use_logo_colors' => true]);
    SiteBranding::factory()->create(['site_id' => $site->id, 'logo_set' => ['primary' => '#334155', 'accent' => '#F97316']]);

    $this->artisan('launchpad:activate-style', ['site' => $site->id, '--dry-run' => true])
        ->expectsOutputToContain('use_logo_colors : true')
        ->expectsOutputToContain('curated pick is IGNORED')
        ->expectsOutputToContain('Dry run')
        ->assertSuccessful();
});

it('--variation force-clears the sticky logo override and pushes the curated variation', function () {
    // The stuck state: use_logo_colors on (with a palette), so activate() would push the logo colors.
    $site = Site::factory()->create(['use_logo_colors' => true]);
    SiteBranding::factory()->create(['site_id' => $site->id, 'logo_set' => ['primary' => '#123B6B', 'accent' => '#1D6FD6']]);

    $client = Mockery::mock(WordpressClient::class);
    // Must push the CURATED slate, never the logo variation, once the flag is cleared.
    $client->shouldReceive('activateStyleVariation')->once()->with('slate', Mockery::type('array'))->andReturn(['updated' => true, 'variation' => 'slate']);
    $client->shouldReceive('pushSiteProfile')->andReturn(['updated' => true]);
    $factory = Mockery::mock(WordpressClientFactory::class);
    $factory->shouldReceive('forSite')->andReturn($client);
    app()->instance(WordpressClientFactory::class, $factory);

    $this->artisan('launchpad:activate-style', ['site' => $site->id, '--variation' => 'slate'])
        ->expectsOutputToContain('Forced: style_variation = slate')
        ->expectsOutputToContain('Applied "Slate & Signal"')
        ->assertSuccessful();

    expect($site->fresh()->use_logo_colors)->toBeFalse()
        ->and($site->fresh()->style_variation)->toBe(StyleVariation::Slate);
});

it('surfaces the theme-missing error verbatim and fails', function () {
    $site = Site::factory()->create(['style_variation' => StyleVariation::Slate->value, 'use_logo_colors' => false]);

    $client = Mockery::mock(WordpressClient::class);
    $client->shouldReceive('activateStyleVariation')->once()->with('slate', Mockery::type('array'))
        ->andReturn(['updated' => false, 'error' => "Style variation 'slate' is not in the active theme."]);
    $client->shouldReceive('pushSiteProfile')->andReturn(['updated' => true]);
    $factory = Mockery::mock(WordpressClientFactory::class);
    $factory->shouldReceive('forSite')->andReturn($client);
    app()->instance(WordpressClientFactory::class, $factory);

    $this->artisan('launchpad:activate-style', ['site' => $site->id])
        ->expectsOutputToContain('is not in the active theme')
        ->assertFailed();
});
