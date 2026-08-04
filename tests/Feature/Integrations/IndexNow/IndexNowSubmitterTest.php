<?php

use App\Integrations\IndexNow\IndexNowSubmitter;
use App\Integrations\Wordpress\WordpressClient;
use App\Integrations\Wordpress\WordpressClientFactory;
use App\Integrations\Wordpress\WordpressException;
use App\Models\Content;
use App\Models\Site;
use Illuminate\Http\Client\Factory;
use Illuminate\Support\Facades\Http;

function inSubmitter(WordpressClientFactory $factory, bool $enabled = true): IndexNowSubmitter
{
    return new IndexNowSubmitter(app(Factory::class), $factory, 'https://api.indexnow.org/indexnow', $enabled, 15);
}

it('mints + deploys a key to the plugin, then POSTs the URL list to IndexNow', function () {
    Http::fake(['*api.indexnow.org*' => Http::response('', 200)]);
    $site = Site::factory()->create(['domain_url' => 'https://spg.example', 'indexnow_key' => null]);

    $wp = Mockery::mock(WordpressClient::class);
    $wp->shouldReceive('pushIndexNowKey')->once()->andReturn(['stored' => true]);
    $factory = Mockery::mock(WordpressClientFactory::class);
    $factory->shouldReceive('forSite')->andReturn($wp);

    $result = inSubmitter($factory)->submit($site, ['https://spg.example/a', 'https://spg.example/b']);

    expect($result['ok'])->toBeTrue()
        ->and($result['submitted'])->toBe(2)
        ->and($site->fresh()->indexnow_key)->not->toBeNull();

    Http::assertSent(function ($r) use ($site) {
        return str_contains($r->url(), 'api.indexnow.org')
            && $r['host'] === 'spg.example'
            && $r['key'] === $site->fresh()->indexnow_key
            && str_ends_with($r['keyLocation'], '.txt')
            && $r['urlList'] === ['https://spg.example/a', 'https://spg.example/b'];
    });
});

it('does not call IndexNow when a fresh key cannot be deployed to the plugin', function () {
    Http::fake();
    $site = Site::factory()->create(['domain_url' => 'https://spg.example', 'indexnow_key' => null]);

    $wp = Mockery::mock(WordpressClient::class);
    $wp->shouldReceive('pushIndexNowKey')->andThrow(new WordpressException('WP unreachable'));
    $factory = Mockery::mock(WordpressClientFactory::class);
    $factory->shouldReceive('forSite')->andReturn($wp);

    $result = inSubmitter($factory)->submit($site, ['https://spg.example/a']);

    expect($result['ok'])->toBeFalse()->and($result['reason'])->toBe('no_key');
    Http::assertNothingSent();
});

it('surfaces a 403 (key file not served yet) with a plugin-update hint', function () {
    Http::fake(['*api.indexnow.org*' => Http::response('', 403)]);
    $site = Site::factory()->create(['domain_url' => 'https://spg.example', 'indexnow_key' => 'abcdef1234567890']);

    $factory = Mockery::mock(WordpressClientFactory::class); // existing key + no redeploy → no WP push

    $result = inSubmitter($factory)->submit($site, ['https://spg.example/a']);

    expect($result['ok'])->toBeFalse()
        ->and($result['status'])->toBe(403)
        ->and(strtolower((string) $result['reason']))->toContain('companion plugin');
});

it('is a no-op when IndexNow is disabled', function () {
    Http::fake();
    $site = Site::factory()->create(['domain_url' => 'https://spg.example']);
    $factory = Mockery::mock(WordpressClientFactory::class);

    $result = inSubmitter($factory, enabled: false)->submit($site, ['https://spg.example/a']);

    expect($result['ok'])->toBeFalse()->and($result['reason'])->toBe('disabled');
    Http::assertNothingSent();
});

it('stamps indexnow_submitted_at on each published page after a successful site submit', function () {
    Http::fake(['*api.indexnow.org*' => Http::response('', 200)]);
    $site = Site::factory()->create(['domain_url' => 'https://spg.example', 'indexnow_key' => 'abcdef1234567890']);

    $wp = Mockery::mock(WordpressClient::class);
    $wp->shouldReceive('pushIndexNowKey')->andReturn(['stored' => true]);
    $factory = Mockery::mock(WordpressClientFactory::class);
    $factory->shouldReceive('forSite')->andReturn($wp);

    $c = Content::factory()->create(['site_id' => $site->id, 'status' => 'published', 'wp_post_id' => 5, 'slug' => 'a', 'indexnow_submitted_at' => null]);
    Content::factory()->create(['site_id' => $site->id, 'status' => 'needs_review', 'wp_post_id' => null, 'slug' => 'draft']); // excluded

    $result = inSubmitter($factory)->submitSite($site);

    expect($result['ok'])->toBeTrue()
        ->and($c->fresh()->indexnow_submitted_at)->not->toBeNull();
});
