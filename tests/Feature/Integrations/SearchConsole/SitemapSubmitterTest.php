<?php

use App\Integrations\SearchConsole\SitemapSubmitter;
use App\Models\GoogleAccount;
use App\Models\Site;
use Illuminate\Support\Facades\Http;

function ssGrant(string $status = 'connected'): void
{
    GoogleAccount::create([
        'credentials' => [
            'access_token' => 'tok',
            'refresh_token' => 'refresh-1',
            'expires_at' => (new DateTimeImmutable('+1 hour'))->format(DATE_ATOM),
        ],
        'status' => $status,
    ]);
}

function ssSitemapsList(int $submitted = 142): array
{
    return ['sitemap' => [[
        'path' => 'https://spg.example/sitemap.xml',
        'lastSubmitted' => '2026-08-01T00:00:00.000Z',
        'isPending' => false,
        'errors' => 0,
        'warnings' => 0,
        'contents' => [['type' => 'web', 'submitted' => $submitted, 'indexed' => 0]],
    ]]];
}

it('submits the site sitemap to Search Console and reports the submitted count', function () {
    ssGrant();
    $site = Site::factory()->create(['gsc_property' => 'sc-domain:spg.example', 'domain_url' => 'https://spg.example']);

    Http::fake([
        '*/sitemaps/*' => Http::response('', 200),          // PUT submit (encoded feedpath)
        '*/sitemaps' => Http::response(ssSitemapsList(142)),  // GET list/status
    ]);

    $result = app(SitemapSubmitter::class)->submit($site);

    expect($result['ok'])->toBeTrue()
        ->and($result['sitemap'])->toBe('https://spg.example/sitemap.xml')
        ->and($result['submitted'])->toBe(142)
        ->and($result['pending'])->toBeFalse();

    // The PUT targets the feedpath = the URL-encoded sitemap URL, with an EMPTY body — Google rejects
    // an empty-array body ("Root element must be a message"), so it must not send `[]`.
    Http::assertSent(fn ($r) => $r->method() === 'PUT'
        && str_contains($r->url(), '/sitemaps/')
        && str_contains($r->url(), rawurlencode('https://spg.example/sitemap.xml'))
        && $r->body() === '');
});

it('surfaces Google\'s reason when the grant lacks write authority (read-only scope / restricted user)', function () {
    ssGrant();
    $site = Site::factory()->create(['gsc_property' => 'sc-domain:spg.example', 'domain_url' => 'https://spg.example']);

    Http::fake([
        '*/sitemaps/*' => Http::response(['error' => ['message' => 'User does not have sufficient permission for site.']], 403),
    ]);

    $result = app(SitemapSubmitter::class)->submit($site);

    expect($result['ok'])->toBeFalse()
        ->and(strtolower((string) $result['reason']))->toContain('permission');
});

it('does not call Google when Search Console is not connected', function () {
    $site = Site::factory()->create(['gsc_property' => null, 'domain_url' => 'https://spg.example']);
    Http::fake();

    $result = app(SitemapSubmitter::class)->submit($site);

    expect($result['ok'])->toBeFalse()->and($result['reason'])->toBe('not_connected');
    Http::assertNothingSent();
});

it('submits for a named site from the command', function () {
    ssGrant();
    Site::factory()->create(['brand_name' => 'SPG', 'gsc_property' => 'sc-domain:spg.example', 'domain_url' => 'https://spg.example']);

    Http::fake([
        '*/sitemaps/*' => Http::response('', 200),
        '*/sitemaps' => Http::response(ssSitemapsList(10)),
    ]);

    $this->artisan('launchpad:submit-sitemap', ['--site' => 'SPG'])
        ->expectsOutputToContain('submitted')
        ->assertSuccessful();
});
