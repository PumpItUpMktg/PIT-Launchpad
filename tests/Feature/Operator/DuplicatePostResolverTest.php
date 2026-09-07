<?php

use App\Enums\ContentKind;
use App\Enums\ContentStatus;
use App\Integrations\Wordpress\WordpressClient;
use App\Integrations\Wordpress\WordpressClientFactory;
use App\Models\Content;
use App\Models\GscUrlDaily;
use App\Models\Redirect;
use App\Models\Scopes\SiteScope;
use App\Models\Site;
use App\Operator\Coverage\DuplicatePostResolver;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Http;

function rPost(Site $s, string $title, string $slug, int $wpId, ?string $publishedAt = '2026-08-01'): Content
{
    return Content::factory()->create([
        'site_id' => $s->id, 'kind' => ContentKind::Post, 'page_type' => null,
        'status' => ContentStatus::Published, 'title' => $title, 'slug' => $slug,
        'wp_post_id' => $wpId, 'published_at' => $publishedAt, 'body' => '<p>x</p>',
    ]);
}

function rGsc(Site $s, string $url, int $impr, ?float $pos): void
{
    GscUrlDaily::create([
        'site_id' => $s->id, 'grain_hash' => hash('sha256', $url), 'date' => now()->subDay()->toDateString(),
        'url' => $url, 'impressions' => $impr, 'clicks' => 0, 'ctr' => 0, 'position' => $pos,
    ]);
}

/** Mock the WP factory so redirect-push + delete don't hit the network. */
function rFakeWp(): void
{
    $client = Mockery::mock(WordpressClient::class);
    $client->shouldReceive('upsertRedirects')->andReturn([]);
    $client->shouldReceive('deleteContent')->andReturnTrue();
    $factory = Mockery::mock(WordpressClientFactory::class);
    $factory->shouldReceive('forSite')->andReturn($client);
    app()->instance(WordpressClientFactory::class, $factory);
}

it('keeps the earner (even when it is the -N twin) and 301s the loser → it', function () {
    $site = Site::factory()->create(['domain_url' => 'https://spg.example']);
    $original = rPost($site, 'Prevent Flooding', 'prevent-flooding-home', 100);
    $twin = rPost($site, 'Prevent Flooding', 'prevent-flooding-home-2', 200);
    rGsc($site, 'https://spg.example/prevent-flooding-home-2/', 1, 11.0); // the -2 earns
    rGsc($site, 'https://spg.example/prevent-flooding-home/', 0, null);

    $plan = app(DuplicatePostResolver::class)->plan($site, 3650);

    expect($plan)->toHaveCount(1)
        ->and($plan[0]['resolvable'])->toBeTrue()
        ->and($plan[0]['reason'])->toBe('earner')
        ->and($plan[0]['keeper']['content_id'])->toBe($twin->id)
        ->and($plan[0]['keeper']['path'])->toBe('/prevent-flooding-home-2')
        ->and($plan[0]['losers'][0]['content_id'])->toBe($original->id)
        ->and($plan[0]['losers'][0]['from'])->toBe('/prevent-flooding-home')
        ->and($plan[0]['losers'][0]['to'])->toBe('/prevent-flooding-home-2');
});

it('keeps the clean slug when every member has zero impressions (slug quality decides)', function () {
    $site = Site::factory()->create(['domain_url' => 'https://spg.example']);
    $clean = rPost($site, 'Sump Pump Maintenance Tips', 'sump-pump-flooding', 100);
    $twin = rPost($site, 'Sump Pump Maintenance Tips', 'sump-pump-flooding-2', 200);
    // no GSC rows → both zero

    $plan = app(DuplicatePostResolver::class)->plan($site, 3650);

    expect($plan[0]['resolvable'])->toBeTrue()
        ->and($plan[0]['reason'])->toBe('clean-slug')
        ->and($plan[0]['keeper']['content_id'])->toBe($clean->id)
        ->and($plan[0]['losers'][0]['content_id'])->toBe($twin->id)
        ->and($plan[0]['losers'][0]['from'])->toBe('/sump-pump-flooding-2')
        ->and($plan[0]['losers'][0]['to'])->toBe('/sump-pump-flooding');
});

it('reports an ambiguous earner (equal impressions) and NEVER resolves it silently — the Radon case', function () {
    $site = Site::factory()->create(['domain_url' => 'https://spg.example']);
    $original = rPost($site, 'Radon Test Tampering', 'radon-test-tampering', 100);
    $twin = rPost($site, 'Radon Test Tampering', 'radon-test-tampering-2', 200);
    // Equal impressions, differing positions — the -2 sits better, which the impression rule can't see.
    rGsc($site, 'https://spg.example/radon-test-tampering/', 2, 7.5);
    rGsc($site, 'https://spg.example/radon-test-tampering-2/', 2, 4.5);

    $plan = app(DuplicatePostResolver::class)->plan($site, 3650);

    expect($plan[0]['resolvable'])->toBeFalse()
        ->and($plan[0]['reason'])->toBe('ambiguous-earner')
        ->and($plan[0]['keeper'])->toBeNull()
        ->and(collect($plan[0]['members'])->pluck('content_id')->sort()->values()->all())
        ->toBe(collect([$original->id, $twin->id])->sort()->values()->all())
        ->and(app(DuplicatePostResolver::class)->apply($site, 3650))->toBe([]); // nothing applied
});

it('settles an ambiguous group with a per-group --keep override (the human names the winner)', function () {
    $site = Site::factory()->create(['domain_url' => 'https://spg.example']);
    $original = rPost($site, 'Radon Test Tampering', 'radon-test-tampering', 100);
    $twin = rPost($site, 'Radon Test Tampering', 'radon-test-tampering-2', 200);
    rGsc($site, 'https://spg.example/radon-test-tampering/', 2, 7.5);
    rGsc($site, 'https://spg.example/radon-test-tampering-2/', 2, 4.5);

    // Pin the -2 (better position) as keeper.
    $plan = app(DuplicatePostResolver::class)->plan($site, 3650, [$twin->id]);

    expect($plan[0]['resolvable'])->toBeTrue()
        ->and($plan[0]['reason'])->toBe('operator-override')
        ->and($plan[0]['keeper']['content_id'])->toBe($twin->id)
        ->and($plan[0]['losers'][0]['content_id'])->toBe($original->id)
        ->and($plan[0]['losers'][0]['to'])->toBe('/radon-test-tampering-2');
});

it('applies: writes the 301, verifies it is SERVING, then removes the loser post', function () {
    rFakeWp();
    $site = Site::factory()->create(['domain_url' => 'https://spg.example']);
    $clean = rPost($site, 'Sump Pump Maintenance Tips', 'sump-pump-flooding', 100);
    $twin = rPost($site, 'Sump Pump Maintenance Tips', 'sump-pump-flooding-2', 200);

    Http::fake([
        'spg.example/sump-pump-flooding-2/*' => Http::response('', 301, ['Location' => 'https://spg.example/sump-pump-flooding/']), // trailing * matches the ?__lpverify= cache-buster
        '*' => Http::response('', 200),
    ]);

    $out = app(DuplicatePostResolver::class)->apply($site, 3650);

    expect($out)->toHaveCount(1)
        ->and($out[0]['redirected'])->toBeTrue()
        ->and($out[0]['verified'])->toBeTrue()
        ->and($out[0]['removed'])->toBeTrue()
        ->and(Redirect::withoutGlobalScope(SiteScope::class)->where('site_id', $site->id)->where('from_url', '/sump-pump-flooding-2')->first()->to_url)->toBe('/sump-pump-flooding')
        ->and(Content::withoutGlobalScope(SiteScope::class)->find($twin->id))->toBeNull()       // loser removed
        ->and(Content::withoutGlobalScope(SiteScope::class)->find($clean->id))->not->toBeNull(); // keeper stays
});

it('NEVER removes the post when the redirect is not confirmed serving (no 404 gap)', function () {
    rFakeWp();
    $site = Site::factory()->create(['domain_url' => 'https://spg.example']);
    rPost($site, 'Sump Pump Maintenance Tips', 'sump-pump-flooding', 100);
    $twin = rPost($site, 'Sump Pump Maintenance Tips', 'sump-pump-flooding-2', 200);

    // The loser URL still serves 200 (redirect NOT live yet) — removal must be withheld.
    Http::fake(['*' => Http::response('', 200)]);

    $out = app(DuplicatePostResolver::class)->apply($site, 3650);

    expect($out[0]['redirected'])->toBeTrue()
        ->and($out[0]['verified'])->toBeFalse()
        ->and($out[0]['removed'])->toBeFalse()
        ->and($out[0]['note'])->toContain('left live')
        ->and(Content::withoutGlobalScope(SiteScope::class)->find($twin->id))->not->toBeNull(); // post kept
});

it('command --execute confirms at ORIGIN and prints a CDN purge reminder naming the removed URLs', function () {
    rFakeWp();
    $site = Site::factory()->create(['brand_name' => 'SPG', 'domain_url' => 'https://spg.example']);
    $clean = rPost($site, 'Sump Pump Maintenance Tips', 'sump-pump-flooding', 100);
    $twin = rPost($site, 'Sump Pump Maintenance Tips', 'sump-pump-flooding-2', 200);
    Http::fake([
        'spg.example/sump-pump-flooding-2/*' => Http::response('', 301, ['Location' => 'https://spg.example/sump-pump-flooding/']),
        '*' => Http::response('', 200),
    ]);

    $code = Artisan::call('launchpad:resolve-duplicate-posts', ['--site' => $site->id, '--days' => 3650, '--execute' => true]);
    $out = Artisan::output();

    expect($code)->toBe(0)
        ->and($out)->toContain('PURGE')                                       // "verified" no longer overstates
        ->and($out)->toContain('https://spg.example/sump-pump-flooding-2/')   // the exact URL to purge
        ->and(Content::withoutGlobalScope(SiteScope::class)->find($twin->id))->toBeNull(); // and it was removed
});

it('command is report-only by default, prints blocked members with their content id, and writes nothing', function () {
    $site = Site::factory()->create(['brand_name' => 'SPG', 'domain_url' => 'https://spg.example']);
    $original = rPost($site, 'Radon Test Tampering', 'radon-test-tampering', 100);
    $twin = rPost($site, 'Radon Test Tampering', 'radon-test-tampering-2', 200);
    rGsc($site, 'https://spg.example/radon-test-tampering/', 2, 7.5);
    rGsc($site, 'https://spg.example/radon-test-tampering-2/', 2, 4.5);

    $code = Artisan::call('launchpad:resolve-duplicate-posts', ['--site' => $site->id, '--days' => 3650]);
    $out = Artisan::output();

    expect($code)->toBe(0)
        ->and($out)->toContain('BLOCKED (ambiguous-earner)')
        ->and($out)->toContain($twin->id)          // the content id to pass to --keep is printed
        ->and($out)->toContain('pin one with --keep')
        ->and(Redirect::withoutGlobalScope(SiteScope::class)->where('site_id', $site->id)->count())->toBe(0)
        ->and(Content::withoutGlobalScope(SiteScope::class)->find($original->id))->not->toBeNull();
});
