<?php

use App\Enums\ContentKind;
use App\Enums\ContentStatus;
use App\Enums\PageType;
use App\Jobs\PublishRedirects;
use App\Locations\ForeignRedirectImporter;
use App\Models\Content;
use App\Models\Redirect;
use App\Models\Scopes\SiteScope;
use App\Models\Site;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Tests\Support\PublishHarness;

function foreignSite(): Site
{
    return PublishHarness::site();
}

function fakeForeign(array $rules, array $tables = [], array $options = [], array $skippedTables = []): void
{
    Http::fake([
        '*/wp-json/launchpad/v1/redirects/foreign*' => Http::response(['rules' => $rules, 'tables' => $tables, 'options' => $options, 'skipped_tables' => $skippedTables]),
    ]);
}

it('plans the import: covered / new / conflict / live / unparsed, against our store and the live pages', function () {
    $site = foreignSite();
    $host = parse_url((string) $site->domain_url, PHP_URL_HOST);
    Content::factory()->create(['site_id' => $site->id, 'kind' => ContentKind::Page, 'page_type' => PageType::Location, 'status' => ContentStatus::Published, 'title' => 'Hoboken, NJ', 'slug' => 'hoboken-nj']);
    Redirect::factory()->create(['site_id' => $site->id, 'from_url' => '/hoboken', 'to_url' => '/hoboken-nj', 'code' => 301, 'status' => 'active']);
    Redirect::factory()->create(['site_id' => $site->id, 'from_url' => '/old-blog', 'to_url' => '/sump-pump-maintenance', 'code' => 301, 'status' => 'active']);
    fakeForeign([
        ['from' => '/hoboken/', 'to' => "https://{$host}/hoboken-nj/", 'code' => 301, 'hits' => 40, 'source' => 'wp_redirects', 'regex' => false],   // covered (same target, absolute form)
        ['from' => '/services/old-drains/', 'to' => '/drain-cleaning/', 'code' => 301, 'hits' => 7, 'source' => 'wp_redirects', 'regex' => false],  // new
        ['from' => '/old-blog/', 'to' => '/radon-mitigation/', 'code' => 301, 'hits' => 2, 'source' => 'wp_redirects', 'regex' => false],          // conflict
        ['from' => '/hoboken-nj/', 'to' => '/somewhere/', 'code' => 301, 'hits' => 0, 'source' => 'wp_redirects', 'regex' => false],               // live page — never shadowed
        ['from' => '^/legacy/(.*)$', 'to' => '/new/$1', 'code' => 301, 'hits' => 0, 'source' => 'wp_redirection_items', 'regex' => true],         // pattern — unparsed
        ['from' => '/flagged-but-plain/', 'to' => '/plain-target/', 'code' => 301, 'hits' => 1, 'source' => 'wp_redirects', 'regex' => true],     // a plugin's regex flag alone is not trusted — new
        ['from' => '/loop/', 'to' => '/loop', 'code' => 301, 'hits' => 0, 'source' => 'wp_redirects', 'regex' => false],                            // self — unparsed
        ['from' => '/gone-for-good/', 'to' => '', 'code' => 410, 'hits' => 0, 'source' => 'wp_redirects', 'regex' => false],                        // new (gone)
        ['from' => 'https://other.example/x', 'to' => '/y', 'code' => 301, 'hits' => 0, 'source' => 'wp_redirects', 'regex' => false],              // another host — unparsed
        ['from' => '/services/old-drains', 'to' => '/dup', 'code' => 301, 'hits' => 0, 'source' => 'wp_redirects', 'regex' => false],              // duplicate path — first wins
    ], [['table' => 'wp_redirects', 'rows' => 9, 'parsed' => 9]]);

    $plan = app(ForeignRedirectImporter::class)->plan($site);

    expect($plan['total'])->toBe(10)
        ->and($plan['covered'])->toBe([['from' => '/hoboken', 'to' => '/hoboken-nj']])
        ->and(collect($plan['new'])->map(fn ($r) => [$r['from'], $r['to'], $r['code']])->all())->toBe([['/services/old-drains', '/drain-cleaning', 301], ['/flagged-but-plain', '/plain-target', 301], ['/gone-for-good', '', 410]])
        ->and($plan['new'][0]['hits'])->toBe(7)
        ->and($plan['conflict'])->toBe([['from' => '/old-blog', 'to' => '/radon-mitigation', 'ours' => '/sump-pump-maintenance', 'source' => 'wp_redirects']])
        ->and($plan['live'])->toBe([['from' => '/hoboken-nj', 'to' => '/somewhere']])
        ->and(collect($plan['unparsed'])->pluck('why')->all())->toBe(['pattern rule', 'redirects to itself', 'source is not a path on this site'])
        ->and(Redirect::withoutGlobalScope(SiteScope::class)->where('site_id', $site->id)->count())->toBe(2); // plan writes nothing
});

it('apply writes only the new rows, and the command previews, applies, and queues the push', function () {
    Queue::fake();
    $site = foreignSite();
    fakeForeign([
        ['from' => '/services/old-drains/', 'to' => '/drain-cleaning/', 'code' => 301, 'hits' => 7, 'source' => 'wp_redirects', 'regex' => false],
        ['from' => '/gone-for-good/', 'to' => '', 'code' => 410, 'hits' => 0, 'source' => 'wp_redirects', 'regex' => false],
    ]);

    $this->artisan('launchpad:import-foreign-redirects', ['--site' => $site->id])
        ->expectsOutputToContain('2 foreign redirect rule(s) found on the live site')
        ->expectsOutputToContain('new      /services/old-drains → /drain-cleaning (301, 7 hit(s))')
        ->expectsOutputToContain('new      /gone-for-good → (gone) (410)')
        ->expectsOutputToContain('Preview only')
        ->assertSuccessful();
    expect(Redirect::withoutGlobalScope(SiteScope::class)->where('site_id', $site->id)->count())->toBe(0);

    $this->artisan('launchpad:import-foreign-redirects', ['--site' => $site->id, '--apply' => true, '--push' => true])
        ->expectsOutputToContain('Wrote 2 redirect(s).')
        ->expectsOutputToContain('Queued the redirect push to WordPress.')
        ->assertSuccessful();
    $rows = Redirect::withoutGlobalScope(SiteScope::class)->where('site_id', $site->id)->get()->keyBy('from_url');
    expect($rows)->toHaveCount(2)
        ->and($rows['/services/old-drains']->to_url)->toBe('/drain-cleaning')
        ->and($rows['/gone-for-good']->code)->toBe(410);
    Queue::assertPushed(PublishRedirects::class, fn (PublishRedirects $j) => $j->siteId === (string) $site->id);

    // Re-running imports nothing new.
    $this->artisan('launchpad:import-foreign-redirects', ['--site' => $site->id])
        ->expectsOutputToContain('2 covered by our store already, 0 new')
        ->assertSuccessful();
});

it('the command surfaces a companion that cannot answer', function () {
    $site = foreignSite();
    Http::fake(['*/wp-json/launchpad/v1/redirects/foreign*' => Http::response(['code' => 'rest_no_route', 'message' => 'No route'], 404)]);

    $this->artisan('launchpad:import-foreign-redirects', ['--site' => $site->id])
        ->expectsOutputToContain('Could not read the other plugin\'s rules')
        ->assertFailed();
});

it('collapses chains inside the plugin\'s own rules to the final destination, and never lists 404-log rows one by one', function () {
    $site = foreignSite();
    fakeForeign([
        ['from' => '/dublin-pa', 'to' => '/fallston-md/dublin-pa', 'code' => 301, 'hits' => 3, 'source' => 'wp_wf301_redirect_rules', 'regex' => false],
        ['from' => '/fallston-md/dublin-pa', 'to' => '/doylestown-pa/dublin-pa', 'code' => 301, 'hits' => 0, 'source' => 'wp_wf301_redirect_rules', 'regex' => false],
        ['from' => '/a', 'to' => '/b', 'code' => 301, 'hits' => 0, 'source' => 'wp_wf301_redirect_rules', 'regex' => false],
        ['from' => '/b', 'to' => '/a', 'code' => 301, 'hits' => 0, 'source' => 'wp_wf301_redirect_rules', 'regex' => false],     // a loop — stops, never spins
        ['from' => '/login', 'to' => '', 'code' => 301, 'hits' => 900, 'source' => 'wp_wf301_redirect_logs', 'regex' => false],
        ['from' => '/auth', 'to' => '', 'code' => 301, 'hits' => 900, 'source' => 'wp_wf301_redirect_logs', 'regex' => false],
    ], [['table' => 'wp_wf301_redirect_rules', 'rows' => 4, 'parsed' => 4, 'columns' => ['id', 'url_from', 'url_to', 'type', 'status', 'regex', 'hits']]], [], [['table' => 'wp_wf301_redirect_logs', 'why' => 'log table']]);

    $plan = app(ForeignRedirectImporter::class)->plan($site);
    $byFrom = collect($plan['new'])->keyBy('from');
    expect($byFrom['/dublin-pa']['to'])->toBe('/doylestown-pa/dublin-pa')
        ->and($byFrom['/fallston-md/dublin-pa']['to'])->toBe('/doylestown-pa/dublin-pa')
        ->and($byFrom['/a']['to'])->toBe('/b');   // the loop stops at the first hop

    $this->artisan('launchpad:import-foreign-redirects', ['--site' => $site->id])
        ->expectsOutputToContain('table wp_wf301_redirect_rules — 4 row(s), 4 parsed as rules · columns: id, url_from, url_to, type, status, regex, hits')
        ->expectsOutputToContain('table wp_wf301_redirect_logs — not read (log table)')
        ->expectsOutputToContain('2 row(s) with no destination (404-log entries, not rules)')
        ->doesntExpectOutputToContain('skipped  /login')
        ->assertSuccessful();
});
