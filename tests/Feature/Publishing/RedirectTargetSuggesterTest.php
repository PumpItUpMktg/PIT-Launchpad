<?php

use App\Enums\ContentStatus;
use App\Metrics\UrlNormalizer;
use App\Models\Content;
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
        ->expectsOutputToContain('the traffic is the thing being guessed with')
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
