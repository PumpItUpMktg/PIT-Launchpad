<?php

use App\Enums\ContentStatus;
use App\Metrics\UrlNormalizer;
use App\Models\Content;
use App\Models\CoverageArea;
use App\Models\GscUrlDaily;
use App\Models\GscUrlQueryDaily;
use App\Models\Site;
use App\Publishing\Redirects\RedirectTargetSuggester;
use App\Support\CurrentSite;
use App\Support\PublicUrl;
use Illuminate\Support\Str;

afterEach(function () {
    CurrentSite::clear();
});

function suggestUrl(Site $site, string $path, int $impressions): void
{
    GscUrlDaily::withoutGlobalScopes()->create([
        'id' => (string) Str::ulid(), 'site_id' => $site->id, 'grain_hash' => Str::random(32),
        'date' => now()->subDays(4)->toDateString(),
        'url' => rtrim((string) $site->domain_url, '/').$path,
        'impressions' => $impressions, 'clicks' => 2, 'position' => 12.0,
    ]);
}

function suggestQuery(Site $site, string $path, string $query, int $impressions): void
{
    GscUrlQueryDaily::withoutGlobalScopes()->create([
        'id' => (string) Str::ulid(), 'site_id' => $site->id, 'grain_hash' => Str::random(32),
        'date' => now()->subDays(4)->toDateString(),
        'url' => rtrim((string) $site->domain_url, '/').$path,
        'query' => $query, 'country' => 'usa', 'device' => 'desktop',
        'impressions' => $impressions, 'clicks' => 1, 'position' => 11.0,
    ]);
}

function suggestPage(Site $site, string $slug, string $title): Content
{
    return Content::factory()->create([
        'site_id' => $site->id, 'slug' => $slug, 'title' => $title, 'status' => ContentStatus::Published,
    ]);
}

it('prefers a page that already ranks for the query over one that merely looks similar', function () {
    $site = Site::factory()->create();
    $legacy = '/services/sewage-pump-services/sewage-pump-replacement';
    suggestUrl($site, $legacy, 33487);
    suggestQuery($site, $legacy, 'sewage ejector pump replacement', 33487);

    // Resembles the legacy slug, but Google has never shown it for the query.
    $lookalike = suggestPage($site, 'sewage-pump-replacement-guide', 'Sewage Pump Replacement Guide');
    suggestUrl($site, UrlNormalizer::path((string) PublicUrl::forContent($site->domain_url, $lookalike)), 100);

    // Different words, and the page Google actually shows for it.
    $ranker = suggestPage($site, 'ejector-pumps', 'Ejector Pumps');
    $ranked = UrlNormalizer::path((string) PublicUrl::forContent($site->domain_url, $ranker));
    suggestUrl($site, $ranked, 9000);
    suggestQuery($site, $ranked, 'sewage ejector pump replacement', 4200);

    $result = app(RedirectTargetSuggester::class)->for($site, $legacy);

    expect($result['top_query'])->toBe('sewage ejector pump replacement')
        ->and($result['impressions'])->toBe(33487)
        ->and($result['candidates'][0]['path'])->toBe($ranked)
        ->and($result['candidates'][0]['shares_query'])->toBe(4200);
});

it('says to build the page when nothing serves the intent', function () {
    $site = Site::factory()->create(['brand_name' => 'Sump Pump Gurus']);
    $legacy = '/services/sewage-pump-services/sewage-pump-replacement';
    suggestUrl($site, $legacy, 33487);
    suggestQuery($site, $legacy, 'sewage ejector pump replacement', 33487);

    // A live site with nothing resembling it and nothing ranking for it.
    suggestPage($site, 'basement-waterproofing', 'Basement Waterproofing');

    $this->artisan('launchpad:suggest-redirect-target', ['--site' => $site->id, '--from' => $legacy])
        ->expectsOutputToContain('No candidate.')
        ->expectsOutputToContain('Build the page rather than redirecting the traffic away')
        ->assertSuccessful();
});

it('marks a resemblance-only suggestion as weak', function () {
    $site = Site::factory()->create(['brand_name' => 'Sump Pump Gurus']);
    $legacy = '/services/sump-pump-services/sump-pump-monitoring-and-alarms';
    suggestUrl($site, $legacy, 3379);
    suggestQuery($site, $legacy, 'sump pump monitor', 3379);

    $similar = suggestPage($site, 'sump-pump-monitoring', 'Sump Pump Monitoring');
    suggestUrl($site, UrlNormalizer::path((string) PublicUrl::forContent($site->domain_url, $similar)), 400);

    $this->artisan('launchpad:suggest-redirect-target', ['--site' => $site->id, '--from' => $legacy])
        ->expectsOutputToContain('resemblance only')
        ->expectsOutputToContain('Weak — not offered.')
        ->doesntExpectOutputToContain('Write it:')
        ->assertSuccessful();
});

it('prints the exact command that writes the redirect', function () {
    $site = Site::factory()->create(['brand_name' => 'Sump Pump Gurus']);
    $legacy = '/services/old-thing';
    suggestUrl($site, $legacy, 5000);
    suggestQuery($site, $legacy, 'old thing', 5000);

    $target = suggestPage($site, 'old-thing', 'Old Thing');
    $path = UrlNormalizer::path((string) PublicUrl::forContent($site->domain_url, $target));
    suggestUrl($site, $path, 900);
    suggestQuery($site, $path, 'old thing', 700);

    $this->artisan('launchpad:suggest-redirect-target', ['--site' => $site->id, '--from' => $legacy])
        ->expectsOutputToContain('launchpad:fix-redirect')
        ->assertSuccessful();
});

it('needs somewhere to start', function () {
    $site = Site::factory()->create(['brand_name' => 'Sump Pump Gurus']);

    $this->artisan('launchpad:suggest-redirect-target', ['--site' => $site->id])->assertFailed();
    $this->artisan('launchpad:suggest-redirect-target')->assertFailed();
});

it('does not let one stray impression on the homepage beat every real candidate', function () {
    $site = Site::factory()->create();
    $legacy = '/sump-pump-installation-diy-costs-more-long-run';
    suggestUrl($site, $legacy, 9152);
    suggestQuery($site, $legacy, 'sump pump installation cost', 9152);

    // The homepage picks up a stray impression on nearly everything. On the real site this was enough to
    // get "/ already ranks for it (1 impression)" a write line over a topical page.
    $home = suggestPage($site, '', 'Home');
    suggestUrl($site, '/', 97908);
    suggestQuery($site, '/', 'sump pump installation cost', 1);

    $topical = suggestPage($site, 'sump-pump-installation', 'Sump Pump Installation');
    suggestUrl($site, UrlNormalizer::path((string) PublicUrl::forContent($site->domain_url, $topical)), 104);

    $result = app(RedirectTargetSuggester::class)->for($site, $legacy);

    expect(collect($result['candidates'])->pluck('path')->all())->not->toContain('/')
        ->and($result['strong'])->toBeFalse();
    expect($home)->toBeInstanceOf(Content::class);
});

it('needs a real share of the query before calling a page a shared ranking', function () {
    $site = Site::factory()->create();
    $legacy = '/services/sewage-pump-services/sewage-pump-replacement';
    suggestUrl($site, $legacy, 33487);
    suggestQuery($site, $legacy, 'sewage ejector pump replacement', 33487);

    // The sump-pump replacement page picked up 32 impressions for a sewage query — a tenth of a percent
    // of what the source earns. That is noise, and on the real site it was offered as the destination.
    $sump = suggestPage($site, 'sump-pump-replacement', 'Sump Pump Replacement');
    $sumpPath = UrlNormalizer::path((string) PublicUrl::forContent($site->domain_url, $sump));
    suggestUrl($site, $sumpPath, 225);
    suggestQuery($site, $sumpPath, 'sewage ejector pump replacement', 32);

    $result = app(RedirectTargetSuggester::class)->for($site, $legacy);

    $candidate = collect($result['candidates'])->firstWhere('path', $sumpPath);
    expect($candidate['shares_query'] ?? 0)->toBe(0)
        ->and($result['strong'])->toBeFalse();
});

it('leaves a core page alone when nothing succeeds it', function () {
    $site = Site::factory()->create(['brand_name' => 'Sump Pump Gurus']);
    suggestUrl($site, '/contact-us', 4724);
    suggestQuery($site, '/contact-us', 'sump pump gurus', 4724);
    suggestPage($site, '', 'Home');
    suggestUrl($site, '/', 97908);
    suggestQuery($site, '/', 'sump pump gurus', 1145);

    // No /contact is published, so there is nothing to route to. On the real site this once printed a
    // fix-redirect line pointing the Contact page at the homepage.
    $this->artisan('launchpad:suggest-redirect-target', ['--site' => $site->id, '--from' => '/contact-us'])
        ->expectsOutputToContain('Leave it.')
        ->doesntExpectOutputToContain('Write it:')
        ->assertSuccessful();
});

it('routes a dead core page to the same page under its new slug', function () {
    $site = Site::factory()->create(['brand_name' => 'Sump Pump Gurus']);
    suggestUrl($site, '/contact-us', 4724);
    suggestQuery($site, '/contact-us', 'sump pump gurus', 4724);

    // /contact-us returned 404 on the live site while /contact returned 200: the old URL was dead, its
    // successor published, and 4,724 impressions were landing on nothing. "Leave it" was the wrong call.
    $contact = suggestPage($site, 'contact', 'Contact');
    $path = UrlNormalizer::path((string) PublicUrl::forContent($site->domain_url, $contact));
    suggestUrl($site, $path, 173);

    $result = app(RedirectTargetSuggester::class)->for($site, '/contact-us');

    expect($result['kind'])->toBe('brand_query')
        ->and($result['strong'])->toBeTrue()
        ->and($result['candidates'][0]['path'])->toBe($path);

    $this->artisan('launchpad:suggest-redirect-target', ['--site' => $site->id, '--from' => '/contact-us'])
        ->expectsOutputToContain('successor is published under its Launchpad slug')
        ->expectsOutputToContain('Write it:')
        ->assertSuccessful();
});

it('sends a town slug to the location tree, not to the homepage', function () {
    $site = Site::factory()->create(['brand_name' => 'Sump Pump Gurus']);
    CoverageArea::withoutGlobalScopes()->create([
        'site_id' => $site->id, 'name' => 'Jenkintown', 'state' => 'PA', 'geo_id' => Str::random(7),
    ]);
    suggestUrl($site, '/jenkintown', 4146);
    suggestQuery($site, '/jenkintown', 'sump pump repair jenkintown', 4146);

    $this->artisan('launchpad:suggest-redirect-target', ['--site' => $site->id, '--from' => '/jenkintown'])
        ->expectsOutputToContain('A town slug.')
        ->doesntExpectOutputToContain('Write it:')
        ->assertSuccessful();
});

it('does not route on a site: operator query', function () {
    $site = Site::factory()->create();
    suggestUrl($site, '/poconos-article', 2657);
    suggestQuery($site, '/poconos-article', 'site:sumppumpgurus.com', 2657);
    suggestPage($site, '', 'Home');
    suggestUrl($site, '/', 97908);
    suggestQuery($site, '/', 'site:sumppumpgurus.com', 137);

    $result = app(RedirectTargetSuggester::class)->for($site, '/poconos-article');

    // Every page "ranks" for a site: search. It carries no information about where the traffic belongs.
    expect($result['top_query'])->toBeNull()
        ->and($result['candidates'])->toBe([]);
});

it('keeps articles out of the unrouted sweep unless asked', function () {
    $site = Site::factory()->create(['brand_name' => 'Sump Pump Gurus']);
    suggestUrl($site, '/how-long-do-sump-pumps-last', 13894);
    suggestQuery($site, '/how-long-do-sump-pumps-last', 'how long do sump pumps last', 13894);
    suggestUrl($site, '/services/rain-water-management/dry-well-installation', 6945);
    suggestQuery($site, '/services/rain-water-management/dry-well-installation', 'dry well installation', 6945);

    $suggester = app(RedirectTargetSuggester::class);

    // An unresolved article belongs in revival; only the service URL is this sweep's business.
    expect(collect($suggester->unrouted($site))->pluck('from')->all())
        ->toBe(['/services/rain-water-management/dry-well-installation'])
        ->and(collect($suggester->unrouted($site, articles: true))->pluck('from')->all())
        ->toContain('/how-long-do-sump-pumps-last');
});
