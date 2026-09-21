<?php

use App\Models\CoverageArea;
use App\Models\GscUrlDaily;
use App\Models\Site;
use App\Publishing\Redirects\RevivalEligibility;
use App\Support\CurrentSite;
use Illuminate\Support\Str;

afterEach(function () {
    CurrentSite::clear();
});

function spg(): Site
{
    return Site::factory()->create(['brand_name' => 'Sump Pump Gurus']);
}

it('refuses to rewrite the pages a customer uses to reach the business', function () {
    $site = spg();
    $e = app(RevivalEligibility::class);

    // Applied, each of these became a blog post and 301'd the real page onto it.
    expect($e->classify($site, '/contact-us', 'sump pump gurus'))->toBe('brand_query')
        ->and($e->classify($site, '/about-us', 'sump pump gurus'))->toBe('brand_query')
        ->and($e->classify($site, '/services', 'sump pump gurus'))->toBe('brand_query');
});

it('catches a core page even when its top query is a topic', function () {
    $site = spg();

    // The reserved list is the belt to the brand-query braces: a Contact page that happens to rank for
    // something topical is still a Contact page.
    expect(app(RevivalEligibility::class)->classify($site, '/contact-us', 'sump pump repair near me'))
        ->toBe('core_page');
});

it('does not mistake the trade for the brand', function () {
    $site = spg();
    $e = app(RevivalEligibility::class);

    // "sump pump gurus" is the brand. "sump pump" is what they do — if that matched, every page on the
    // site would be held back.
    expect($e->classify($site, '/your-sump-pumps-worst-enemies', 'sump pump'))->toBe(RevivalEligibility::ARTICLE)
        ->and($e->classify($site, '/some-page', 'sump pump gurus reviews'))->toBe('brand_query');
});

it('sends an old service URL to the redirect path, not to a blog post', function () {
    $site = spg();

    // Commercial intent earned that ranking; rewriting it as an article throws the intent away.
    expect(app(RevivalEligibility::class)
        ->classify($site, '/services/sewage-pump-services/sewage-pump-maintenance', 'sewage pump maintenance'))
        ->toBe('service_page');
});

it('recognises a bare town slug, but not an article about a town', function () {
    $site = spg();
    CoverageArea::withoutGlobalScopes()->create([
        'site_id' => $site->id, 'name' => 'Jenkintown', 'state' => 'PA', 'geo_id' => Str::random(7),
    ]);
    $e = app(RevivalEligibility::class);

    expect($e->classify($site, '/jenkintown', 'sump pump repair jenkintown'))->toBe('town_page')
        // A nested or descriptive slug is an article, whatever town it names.
        ->and($e->classify($site, '/jenkintown-sump-pump-tips', 'sump pump tips'))->toBe(RevivalEligibility::ARTICLE);
});

it('ignores a ranking earned by an SEO running a site: search', function () {
    $site = spg();

    // /poconos ranked for exactly this. It is a diagnostic, not demand.
    expect(app(RevivalEligibility::class)->classify($site, '/poconos', 'site:sumppumpgurus.com'))
        ->toBe('operator_query');
});

it('still revives a genuine abandoned article', function () {
    $site = spg();

    expect(app(RevivalEligibility::class)
        ->classify($site, '/how-to-test-sump-pump-flow-rate', 'sump pump flow rate calculation'))
        ->toBe(RevivalEligibility::ARTICLE);
});

it('holds the non-articles out of the plan and names what they are', function () {
    $site = spg();
    foreach ([['/contact-us', 4724, 'sump pump gurus'], ['/how-to-test-sump-pump-flow-rate', 4810, 'sump pump flow rate calculation']] as [$path, $impressions, $query]) {
        GscUrlDaily::withoutGlobalScopes()->create([
            'id' => (string) Str::ulid(), 'site_id' => $site->id, 'grain_hash' => Str::random(32),
            'date' => now()->subDays(3)->toDateString(),
            'url' => rtrim((string) $site->domain_url, '/').$path,
            'impressions' => $impressions, 'clicks' => 2, 'position' => 14.0,
        ]);
        GscUrlDaily::withoutGlobalScopes()->create([
            'id' => (string) Str::ulid(), 'site_id' => $site->id, 'grain_hash' => Str::random(32),
            'date' => now()->subDays(2)->toDateString(),
            'url' => rtrim((string) $site->domain_url, '/').$path,
            'impressions' => 1, 'clicks' => 0, 'position' => 14.0,
        ]);
    }

    // Each expectation consumes one WRITE, so two substrings from the same printed line can never both
    // match — every assertion below targets a different line.
    //
    // (No query rows in this fixture, so topQuery() is null and the brand-query rung cannot fire; the
    // reserved-path list catches /contact-us instead, which is exactly why both rungs exist.)
    $this->artisan('launchpad:revive-legacy-content', ['--site' => $site->id, '--min-impressions' => 2500])
        ->expectsOutputToContain('/how-to-test-sump-pump-flow-rate')
        ->expectsOutputToContain('held back')
        ->expectsOutputToContain('/contact-us')
        ->expectsOutputToContain('destructive for anything else')
        ->assertSuccessful();

    // The classification itself is asserted directly rather than through the console, where one line can
    // only satisfy one expectation.
    expect(app(RevivalEligibility::class)->classify($site, '/contact-us', null))->toBe('core_page');
});
