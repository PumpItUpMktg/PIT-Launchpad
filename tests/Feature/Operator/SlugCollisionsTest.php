<?php

use App\Enums\ContentStatus;
use App\Enums\UserRole;
use App\Integrations\Wordpress\WordpressClient;
use App\Integrations\Wordpress\WordpressClientFactory;
use App\Integrations\Wordpress\WordpressException;
use App\Models\Content;
use App\Models\GscUrlDaily;
use App\Models\PageIndexState;
use App\Models\Site;
use App\Models\User;
use App\Operator\Coverage\SlugCollisions;
use App\Support\CurrentSite;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;

beforeEach(function () {
    $this->actingAs(User::factory()->create(['role' => UserRole::Operator]));
});

afterEach(function () {
    CurrentSite::clear();
});

function collisionGscUrl(Site $site, string $path, int $impressions): void
{
    GscUrlDaily::withoutGlobalScopes()->create([
        'id' => (string) Str::ulid(), 'site_id' => $site->id, 'grain_hash' => Str::random(32),
        'date' => now()->subDays(3)->toDateString(), 'url' => 'https://spg.example'.$path,
        'impressions' => $impressions, 'clicks' => 1, 'position' => 8.0,
    ]);
}

function collisionVerdict(Site $site, ?Content $content, string $path, string $verdict): void
{
    PageIndexState::create([
        'site_id' => $site->id, 'content_id' => $content?->id, 'origin' => $content === null ? 'discovered' : 'content',
        'url' => 'https://spg.example'.$path.'/', 'url_normalized' => 'https://spg.example'.$path,
        'coverage_state' => $verdict === 'PASS' ? 'Submitted and indexed' : $verdict, 'index_verdict' => $verdict,
    ]);
}

/**
 * Four published posts, each with a numbered twin Google has shown:
 *   cost      → WordPress serves OUR page at /cost-2/ (a legacy published post holds /cost/)
 *   install   → ours at /install/; a different page answers /install-3/ (legacy duplicate, live)
 *   valve     → ours at /valve/; /valve-2/ already 301s to it
 *   battery   → ours at /battery/; /battery-2/ is a 404
 */
function collisionSite(): array
{
    $site = Site::factory()->create(['brand_name' => 'SPG', 'domain_url' => 'https://spg.example']);
    $post = fn (string $slug) => Content::factory()->post()->create(['site_id' => $site->id, 'status' => ContentStatus::Published, 'title' => ucfirst($slug), 'slug' => $slug, 'wp_post_id' => random_int(100, 999)]);
    $cost = $post('cost');
    $install = $post('install');
    $valve = $post('valve');
    $battery = $post('battery');

    foreach (['/cost/' => 500, '/cost-2/' => 189_317, '/install/' => 9_000, '/install-3/' => 72_008, '/valve/' => 3_000, '/valve-2/' => 59_516, '/battery/' => 2_000, '/battery-2/' => 48_957, '/maintenance-101/' => 7_073, '/blog/page/2/' => 10] as $path => $n) {
        collisionGscUrl($site, $path, $n);
    }
    collisionVerdict($site, $cost, '/cost', 'PASS');              // the verdict we hold describes the legacy holder
    collisionVerdict($site, null, '/cost-2', 'PASS');             // our real page, captured as a discovered URL
    collisionVerdict($site, $install, '/install', 'PASS');

    return compact('site', 'cost', 'install', 'valve', 'battery');
}

function fakeCollisionDiagnose(array $f, bool $fail = false): void
{
    $client = Mockery::mock(WordpressClient::class);
    $client->shouldReceive('diagnoseContent')->andReturnUsing(function (string $contentId, string $slug) use ($f, $fail): array {
        if ($fail) {
            throw new WordpressException('WordPress /content/diagnose returned HTTP 500');
        }
        $live = $contentId === $f['cost']->id ? 'cost-2' : $slug;

        return [
            'content_id' => $contentId, 'found' => true, 'post_name' => $live, 'permalink' => "https://spg.example/{$live}/",
            'slug_drifted' => $live !== $slug,
            'slug_holder' => $live !== $slug ? ['wp_post_id' => 12, 'status' => 'publish', 'reclaimable' => false] : null,
        ];
    });
    $factory = Mockery::mock(WordpressClientFactory::class);
    $factory->shouldReceive('forSite')->andReturn($client);
    app()->instance(WordpressClientFactory::class, $factory);
}

function fakeTwinAnswers(): void
{
    Http::fake([
        'https://spg.example/cost-2/' => Http::response('<html>ours</html>', 200),
        'https://spg.example/install-3/' => Http::response('<html>legacy</html>', 200),
        'https://spg.example/valve-2/' => Http::response('', 301, ['Location' => 'https://spg.example/valve/']),
        'https://spg.example/battery-2/' => Http::response('', 404),
    ]);
}

it('tells where our page really lives for each numbered twin, from WordPress and the twin itself', function () {
    $f = collisionSite();
    fakeCollisionDiagnose($f);
    fakeTwinAnswers();

    $r = app(SlugCollisions::class)->for($f['site']);
    $byTitle = collect($r['pages'])->keyBy('title');

    expect($r['pages'])->toHaveCount(4)
        ->and($r['live_error'])->toBeNull()
        // Maintenance 101 is a title, /blog/page/2/ is pagination — neither is a twin of anything.
        ->and($byTitle->keys()->all())->toBe(['Cost', 'Install', 'Valve', 'Battery']);

    $cost = $byTitle['Cost'];
    expect($cost['state'])->toBe(SlugCollisions::OURS_AT_TWIN)
        ->and($cost['wp_slug'])->toBe('cost-2')
        ->and($cost['holder'])->toBe(['status' => 'publish', 'reclaimable' => false])
        ->and($cost['verdict'])->toBe('PASS')                 // the verdict we hold — the legacy holder's
        ->and($cost['impressions'])->toBe(500)
        ->and($cost['twins'][0]['url'])->toBe('https://spg.example/cost-2/')
        ->and($cost['twins'][0]['impressions'])->toBe(189_317)
        ->and($cost['twins'][0]['answer'])->toBe('our page')
        ->and($cost['action'])->toContain('legacy publish page');

    expect($byTitle['Install']['state'])->toBe(SlugCollisions::TWIN_LIVE)
        ->and($byTitle['Install']['twins'][0]['answer'])->toBe('another page')
        ->and($byTitle['Valve']['state'])->toBe(SlugCollisions::TWIN_REDIRECTS)
        ->and($byTitle['Valve']['twins'][0]['location'])->toBe('https://spg.example/valve/')
        ->and($byTitle['Battery']['state'])->toBe(SlugCollisions::TWIN_GONE)
        ->and($byTitle['Battery']['twins'][0]['status'])->toBe(404)
        ->and($r['counts'])->toBe([SlugCollisions::OURS_AT_TWIN => 1, SlugCollisions::TWIN_LIVE => 1, SlugCollisions::TWIN_REDIRECTS => 1, SlugCollisions::TWIN_GONE => 1]);
});

it('reports unknown rather than guessing when WordPress cannot be read', function () {
    $f = collisionSite();
    fakeCollisionDiagnose($f, fail: true);
    fakeTwinAnswers();

    $r = app(SlugCollisions::class)->for($f['site']);

    expect($r['live_error'])->toContain('HTTP 500')
        ->and(collect($r['pages'])->pluck('state')->unique()->all())->toBe([SlugCollisions::UNKNOWN])
        ->and(collect($r['pages'])->firstWhere('title', 'Cost')['wp_slug'])->toBeNull();
});

it('lists the twins without touching the network when live is off', function () {
    $f = collisionSite();
    Http::fake();
    $factory = Mockery::mock(WordpressClientFactory::class);
    $factory->shouldNotReceive('forSite');
    app()->instance(WordpressClientFactory::class, $factory);

    $r = app(SlugCollisions::class)->for($f['site'], live: false);

    expect($r['pages'])->toHaveCount(4)
        ->and($r['pages'][0]['twins'][0]['answer'])->toBe('unknown');
    Http::assertNothingSent();
});

it('adopts the URL WordPress serves — only for pages it serves at a twin — and drops the verdict that was never ours', function () {
    $f = collisionSite();
    fakeCollisionDiagnose($f);
    fakeTwinAnswers();
    $service = app(SlugCollisions::class);

    $done = $service->repoint($f['site'], $service->for($f['site']));

    expect($done['repointed'])->toBe([['content_id' => (string) $f['cost']->id, 'title' => 'Cost', 'from' => 'cost', 'to' => 'cost-2']])
        ->and($done['skipped'])->toBe([])
        ->and($f['cost']->fresh()->slug)->toBe('cost-2')
        ->and($f['install']->fresh()->slug)->toBe('install')
        ->and($f['valve']->fresh()->slug)->toBe('valve')
        ->and($f['battery']->fresh()->slug)->toBe('battery');

    // The PASS we held at /cost/ described the legacy post — gone; the discovered row at /cost-2/ stays
    // for the next sync to re-key to our content.
    expect(PageIndexState::withoutGlobalScopes()->where('content_id', $f['cost']->id)->count())->toBe(0)
        ->and(PageIndexState::withoutGlobalScopes()->where('url_normalized', 'https://spg.example/cost-2')->exists())->toBeTrue();

    // Idempotent: once we store /cost-2/, it is ours and no longer a twin of anything.
    expect(collect($service->for($f['site'])['pages'])->pluck('title')->all())->not->toContain('Cost');
});

it('refuses to adopt a slug another page already stores', function () {
    $f = collisionSite();
    Content::factory()->post()->create(['site_id' => $f['site']->id, 'status' => ContentStatus::NeedsReview, 'title' => 'Squatter', 'slug' => 'cost-2']);
    fakeCollisionDiagnose($f);
    fakeTwinAnswers();
    $service = app(SlugCollisions::class);

    $done = $service->repoint($f['site'], $service->for($f['site']));

    expect($done['repointed'])->toBe([])
        ->and($done['skipped'][0]['reason'])->toContain('cost-2')
        ->and($f['cost']->fresh()->slug)->toBe('cost');
});

it('the command reports first and writes only with --execute', function () {
    $f = collisionSite();
    fakeCollisionDiagnose($f);
    fakeTwinAnswers();

    $this->artisan('launchpad:check-slug-collisions --site=SPG')
        ->expectsOutputToContain('numbered twins of pages we publish')
        ->expectsOutputToContain('1 page(s) WordPress serves at a numbered URL')
        ->expectsOutputToContain('Dry run')
        ->assertSuccessful();
    expect($f['cost']->fresh()->slug)->toBe('cost');

    $this->artisan('launchpad:check-slug-collisions --site=SPG --execute')
        ->expectsOutputToContain('Adopted')
        ->expectsOutputToContain('1 page(s) repointed, 0 skipped')
        ->assertSuccessful();
    expect($f['cost']->fresh()->slug)->toBe('cost-2');
});

it('says none when no twin of a published page has been shown', function () {
    $site = Site::factory()->create(['brand_name' => 'Quiet', 'domain_url' => 'https://spg.example']);
    Content::factory()->post()->create(['site_id' => $site->id, 'status' => ContentStatus::Published, 'slug' => 'cost']);
    collisionGscUrl($site, '/cost/', 100);
    collisionGscUrl($site, '/legacy-post-2/', 100); // a twin of something we never published

    $this->artisan('launchpad:check-slug-collisions --site=Quiet --no-live')
        ->expectsOutputToContain('None.')
        ->assertSuccessful();
});
