<?php

use App\Integrations\BingWebmaster\BingWebmaster;
use App\Integrations\BingWebmaster\NullBingWebmaster;
use App\Models\Site;
use Illuminate\Cache\ArrayStore;
use Illuminate\Cache\Repository;
use Illuminate\Http\Client\Factory;
use Illuminate\Support\Facades\Http;

function bwt(string $apiKey = 'agency-key'): BingWebmaster
{
    return new BingWebmaster(app(Factory::class), new Repository(new ArrayStore), $apiKey, 'https://ssl.bing.com/webmaster/api.svc/json', 15, 0);
}

function bwtSite(array $overrides = []): Site
{
    return Site::factory()->create(array_merge([
        'domain_url' => 'https://spg.example',
        'bing_site_url' => 'https://spg.example',
    ], $overrides));
}

it('is not connected without an API key or without a bing_site_url', function () {
    expect(bwt('')->connected(bwtSite()))->toBeFalse()
        ->and(bwt('k')->connected(bwtSite(['bing_site_url' => null])))->toBeFalse()
        ->and(bwt('k')->connected(bwtSite()))->toBeTrue();
});

it('parses GetPageQueryStats into page stats + queries (grouped, impressions-desc)', function () {
    Http::fake(['*GetPageQueryStats*' => Http::response(['d' => [
        ['Query' => 'sump pump bedminster', 'Impressions' => 120, 'Clicks' => 6, 'AvgImpressionPosition' => 4.0],
        ['Query' => 'sump pump repair', 'Impressions' => 40, 'Clicks' => 1, 'AvgImpressionPosition' => 8.0],
        ['Query' => 'sump pump repair', 'Impressions' => 10, 'Clicks' => 1, 'AvgImpressionPosition' => 6.0], // same query → grouped
    ]], 200)]);

    $site = bwtSite();
    $stats = bwt()->pageStats($site, '/bedminster-nj');
    $queries = bwt()->pageQueries($site, '/bedminster-nj');

    expect($stats)->not->toBeNull()
        ->and($stats->impressions)->toBe(170)   // 120 + 40 + 10
        ->and($stats->clicks)->toBe(8);

    expect($queries[0]->query)->toBe('sump pump bedminster')   // most impressions first
        ->and($queries[0]->impressions)->toBe(120)
        ->and($queries[1]->query)->toBe('sump pump repair')
        ->and($queries[1]->impressions)->toBe(50);             // 40 + 10 grouped
});

it('returns null stats when Bing has no query rows for the page', function () {
    Http::fake(['*GetPageQueryStats*' => Http::response(['d' => []], 200)]);

    expect(bwt()->pageStats(bwtSite(), '/x'))->toBeNull()
        ->and(bwt()->pageQueries(bwtSite(), '/x'))->toBe([]);
});

it('degrades to null on a transient API error, never throws into the board', function () {
    Http::fake(['*GetPageQueryStats*' => Http::response('', 503)]);

    expect(bwt()->pageStats(bwtSite(), '/x'))->toBeNull();
});

it('the Null adapter is never connected and returns nothing', function () {
    $null = new NullBingWebmaster;
    expect($null->connected(bwtSite()))->toBeFalse()
        ->and($null->pageStats(bwtSite(), '/x'))->toBeNull()
        ->and($null->pageQueries(bwtSite(), '/x'))->toBe([]);
});
