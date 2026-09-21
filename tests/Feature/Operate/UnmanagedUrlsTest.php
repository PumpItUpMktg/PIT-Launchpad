<?php

use App\Enums\ContentStatus;
use App\Enums\UserRole;
use App\Models\Content;
use App\Models\GscUrlDaily;
use App\Models\Site;
use App\Models\User;
use App\Operator\Coverage\UnmanagedUrls;
use App\Support\CurrentSite;
use App\Support\PublicUrl;
use Illuminate\Support\Str;

beforeEach(function () {
    $this->actingAs(User::factory()->create(['role' => UserRole::Operator]));
});

afterEach(function () {
    CurrentSite::clear();
});

function gscUrl(Site $site, string $url, int $impressions, float $position = 11.0, int $clicks = 1): void
{
    GscUrlDaily::withoutGlobalScopes()->create([
        'id' => (string) Str::ulid(),
        'site_id' => $site->id,
        'grain_hash' => Str::random(32),
        'date' => now()->subDays(3)->toDateString(),
        'url' => $url,
        'impressions' => $impressions,
        'clicks' => $clicks,
        'position' => $position,
    ]);
}

it('separates the pages we published from the WordPress surface around them', function () {
    $site = Site::factory()->create();
    $page = Content::factory()->create([
        'site_id' => $site->id, 'slug' => 'sump-pump-repair', 'status' => ContentStatus::Published,
    ]);
    gscUrl($site, (string) PublicUrl::forContent($site->domain_url, $page), 300);

    // The surface WordPress generates and nobody wrote — including the category archives our own publish
    // pipeline creates, one per silo.
    gscUrl($site, rtrim((string) $site->domain_url, '/').'/category/sump-pumps/', 90);
    gscUrl($site, rtrim((string) $site->domain_url, '/').'/tag/flooding/', 40);
    gscUrl($site, rtrim((string) $site->domain_url, '/').'/blog/page/2/', 12);
    gscUrl($site, rtrim((string) $site->domain_url, '/').'/author/admin/', 5);

    $r = app(UnmanagedUrls::class)->for($site);

    expect($r['managed'])->toBe(1)
        ->and($r['unmanaged'])->toBe(4)
        ->and($r['unmanaged_impressions'])->toBe(147)
        ->and(array_keys($r['buckets']))
        ->toContain('category archive', 'tag archive', 'pagination', 'author archive');
});

it('labels an unrecognised path as an other page rather than guessing', function () {
    $site = Site::factory()->create();
    gscUrl($site, rtrim((string) $site->domain_url, '/').'/some-legacy-landing-page/', 70);

    $r = app(UnmanagedUrls::class)->for($site);

    // A wrong label would send someone hunting a problem that is not there.
    expect($r['buckets']['other page']['urls'])->toBe(1);
});

it('says plainly that an archive nobody reaches is invisible to it', function () {
    $site = Site::factory()->create(['brand_name' => 'Sump Pump Gurus']);
    gscUrl($site, rtrim((string) $site->domain_url, '/').'/category/sump-pumps/', 90);

    $this->artisan('launchpad:report-unmanaged-urls', ['--site' => $site->id])
        ->expectsOutputToContain('the two totals are not meant to match')
        ->expectsOutputToContain('Google exposes no API for that report')
        ->assertSuccessful();
});

it('reports a site with nothing outside the published set', function () {
    $site = Site::factory()->create();
    $page = Content::factory()->create([
        'site_id' => $site->id, 'slug' => 'only-page', 'status' => ContentStatus::Published,
    ]);
    gscUrl($site, (string) PublicUrl::forContent($site->domain_url, $page), 10);

    $this->artisan('launchpad:report-unmanaged-urls', ['--site' => $site->id])
        ->expectsOutputToContain('Nothing outside the published set has earned an impression.')
        ->assertSuccessful();
});

it('refuses to guess which site', function () {
    $this->artisan('launchpad:report-unmanaged-urls')->assertFailed();
});

it('tells our own numbered duplicate apart from a legacy one', function () {
    $site = Site::factory()->create();
    $ours = Content::factory()->create([
        'site_id' => $site->id, 'slug' => 'how-to-install-a-sump-pump-correctly', 'status' => ContentStatus::Published,
    ]);
    gscUrl($site, (string) PublicUrl::forContent($site->domain_url, $ours), 500);

    $root = rtrim((string) $site->domain_url, '/');
    // WordPress refused our slug and served the content at -2. The URL we believe in is not the one
    // Google indexed — that is ours to fix.
    gscUrl($site, $root.'/how-to-install-a-sump-pump-correctly-2/', 87949);
    // A numbered duplicate of something we never published: legacy content competing with itself.
    gscUrl($site, $root.'/sump-pump-installation-cost-breakdown-3/', 189317);

    $r = app(UnmanagedUrls::class)->for($site);

    expect($r['buckets']['duplicate of a published page']['urls'])->toBe(1)
        ->and($r['buckets']['numbered twin (not ours)']['urls'])->toBe(1)
        ->and($r['managed'])->toBe(1)
        ->and($r['managed_impressions'])->toBe(500);
});

it('does not mistake pagination for a numbered duplicate', function () {
    $site = Site::factory()->create();
    gscUrl($site, rtrim((string) $site->domain_url, '/').'/blog/page/2/', 12);

    // /blog/page/2 is not a twin of /blog/page.
    expect(app(UnmanagedUrls::class)->for($site)['buckets'])
        ->toHaveKey('pagination')
        ->not->toHaveKey('numbered twin (not ours)');
});

it('gives the unmanaged impressions a denominator', function () {
    $site = Site::factory()->create();
    $ours = Content::factory()->create([
        'site_id' => $site->id, 'slug' => 'managed', 'status' => ContentStatus::Published,
    ]);
    gscUrl($site, (string) PublicUrl::forContent($site->domain_url, $ours), 250);
    gscUrl($site, rtrim((string) $site->domain_url, '/').'/legacy-thing/', 750);

    // A big unmanaged number means nothing without what the managed set earned beside it.
    $this->artisan('launchpad:report-unmanaged-urls', ['--site' => $site->id])
        ->expectsOutputToContain('75% of everything this property has earned')
        ->assertSuccessful();
});

it('bands the unmanaged traffic by where it actually ranks', function () {
    $site = Site::factory()->create();
    $root = rtrim((string) $site->domain_url, '/');

    gscUrl($site, $root.'/page-one-winner/', 1000, position: 2.4, clicks: 90);
    gscUrl($site, $root.'/near-miss/', 5000, position: 14.0, clicks: 20);
    gscUrl($site, $root.'/deep-also-ran/', 8000, position: 26.5, clicks: 3);

    $bands = app(UnmanagedUrls::class)->for($site)['position_bands'];

    expect(array_keys($bands))->toBe(['1–3', '11–20', '21+'])
        ->and($bands['1–3']['clicks'])->toBe(90)
        ->and($bands['11–20']['impressions'])->toBe(5000)
        ->and($bands['21+']['urls'])->toBe(1);
});

it('weights position by impressions, not by day', function () {
    $site = Site::factory()->create();
    $url = rtrim((string) $site->domain_url, '/').'/volatile/';

    // One quiet day at position 3 must not outvote a busy day at 23.
    gscUrl($site, $url, 10, position: 3.0, clicks: 0);
    GscUrlDaily::withoutGlobalScopes()->create([
        'id' => (string) Str::ulid(), 'site_id' => $site->id, 'grain_hash' => Str::random(32),
        'date' => now()->subDays(4)->toDateString(), 'url' => $url,
        'impressions' => 990, 'clicks' => 5, 'position' => 23.0,
    ]);

    expect(array_keys(app(UnmanagedUrls::class)->for($site)['position_bands']))->toBe(['21+']);
});

it('reports a URL with no stored position as unknown rather than guessing a band', function () {
    $site = Site::factory()->create();
    GscUrlDaily::withoutGlobalScopes()->create([
        'id' => (string) Str::ulid(), 'site_id' => $site->id, 'grain_hash' => Str::random(32),
        'date' => now()->subDays(2)->toDateString(),
        'url' => rtrim((string) $site->domain_url, '/').'/no-position/',
        'impressions' => 40, 'clicks' => 0, 'position' => null,
    ]);

    expect(app(UnmanagedUrls::class)->for($site)['position_bands'])->toHaveKey('unknown');
});
