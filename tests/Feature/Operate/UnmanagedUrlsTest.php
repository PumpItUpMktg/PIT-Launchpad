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

function gscUrl(Site $site, string $url, int $impressions): void
{
    GscUrlDaily::withoutGlobalScopes()->create([
        'id' => (string) Str::ulid(),
        'site_id' => $site->id,
        'grain_hash' => Str::random(32),
        'date' => now()->subDays(3)->toDateString(),
        'url' => $url,
        'impressions' => $impressions,
        'clicks' => 1,
        'position' => 11.0,
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
