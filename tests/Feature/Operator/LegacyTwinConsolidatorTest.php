<?php

use App\Enums\UserRole;
use App\Integrations\Wordpress\WordpressClient;
use App\Integrations\Wordpress\WordpressClientFactory;
use App\Integrations\Wordpress\WordpressException;
use App\Models\Redirect;
use App\Models\User;
use App\Operator\Coverage\LegacyTwinConsolidator;
use App\Support\CurrentSite;
use Illuminate\Support\Facades\Http;
use Mockery\MockInterface;

beforeEach(function () {
    $this->actingAs(User::factory()->create(['role' => UserRole::Operator]));
});

afterEach(function () {
    CurrentSite::clear();
});

/** The plugin, faked: redirects always land; retire answers per path (a closure), default "retired". */
function fakeConsolidationWp(?Closure $retire = null): MockInterface
{
    $client = Mockery::mock(WordpressClient::class);
    $client->shouldReceive('upsertRedirects')->andReturn([]);
    $client->shouldReceive('retirePost')->andReturnUsing($retire ?? fn (string $path): array => ['path' => $path, 'wp_post_id' => 7, 'retired' => true]);
    $factory = Mockery::mock(WordpressClientFactory::class);
    $factory->shouldReceive('forSite')->andReturn($client);
    app()->instance(WordpressClientFactory::class, $factory);

    return $client;
}

/** Origin serves every cost-breakdown loser as a 301 → -3; the valve loser answers 200 (redirect not live). */
function fakeConsolidationOrigin(): void
{
    Http::fake([
        'spg.example/sump-pump-installation-cost-breakdown/*' => Http::response('', 301, ['Location' => 'https://spg.example/sump-pump-installation-cost-breakdown-3/']),
        'spg.example/sump-pump-installation-cost-breakdown-8/*' => Http::response('', 301, ['Location' => 'https://spg.example/sump-pump-installation-cost-breakdown-3/']),
        'spg.example/when-to-replace-sump-pump-check-valve/*' => Http::response('<html>still live</html>', 200),
        '*' => Http::response('', 200),
    ]);
}

it('writes the 301, pushes it, verifies it is serving, then retires the loser — and never retires an unverified one', function () {
    $site = twinSite();
    $client = fakeConsolidationWp();
    fakeConsolidationOrigin();

    $out = app(LegacyTwinConsolidator::class)->apply($site);
    $byFrom = collect($out)->keyBy('from');

    // The ambiguous install group is not touched at all.
    expect($byFrom->keys()->all())->toBe(['/sump-pump-installation-cost-breakdown', '/sump-pump-installation-cost-breakdown-8', '/when-to-replace-sump-pump-check-valve']);

    foreach (['/sump-pump-installation-cost-breakdown', '/sump-pump-installation-cost-breakdown-8'] as $from) {
        expect($byFrom[$from]['to'])->toBe('/sump-pump-installation-cost-breakdown-3')
            ->and($byFrom[$from]['redirected'])->toBeTrue()
            ->and($byFrom[$from]['verified'])->toBeTrue()
            ->and($byFrom[$from]['removed'])->toBeTrue()
            ->and($byFrom[$from]['note'])->toBe('redirected + retired (trashed)');
        expect(Redirect::withoutGlobalScopes()->where('site_id', $site->id)->where('from_url', $from)->first())
            ->to_url->toBe('/sump-pump-installation-cost-breakdown-3')
            ->code->toBe(301)
            ->status->toBe('active');
    }

    // The valve loser's redirect was written and pushed, but origin still serves the page → NOT retired.
    $valve = $byFrom['/when-to-replace-sump-pump-check-valve'];
    expect($valve['redirected'])->toBeTrue()
        ->and($valve['verified'])->toBeFalse()
        ->and($valve['removed'])->toBeFalse()
        ->and($valve['note'])->toContain('not confirmed serving');
    $client->shouldNotHaveReceived('retirePost', ['/when-to-replace-sump-pump-check-valve']);
    $client->shouldHaveReceived('retirePost')->twice();
});

it('leaves the post in place behind the 301 when the plugin is too old to retire, and says so', function () {
    $site = twinSite();
    fakeConsolidationWp(fn () => throw new WordpressException('WordPress retire endpoint not found (HTTP 404) — update the Launchpad companion plugin (needs 0.9.51+, launchpad/v1 post retire).'));
    fakeConsolidationOrigin();

    $out = collect(app(LegacyTwinConsolidator::class)->apply($site))->keyBy('from');

    expect($out['/sump-pump-installation-cost-breakdown-8']['verified'])->toBeTrue()
        ->and($out['/sump-pump-installation-cost-breakdown-8']['removed'])->toBeFalse()
        ->and($out['/sump-pump-installation-cost-breakdown-8']['note'])->toContain('0.9.51');
});

it('respects redirects that already exist: a loser routed elsewhere is left alone, a redirecting keeper skips its group', function () {
    $site = twinSite();
    // The legacy planner already sent -8 to one of our pages; and the valve keeper is itself a redirect source.
    Redirect::create(['site_id' => $site->id, 'from_url' => '/sump-pump-installation-cost-breakdown-8', 'to_url' => '/our-guide', 'code' => 301, 'status' => 'active', 'source' => 'migration']);
    Redirect::create(['site_id' => $site->id, 'from_url' => '/when-to-replace-sump-pump-check-valve-2', 'to_url' => '/our-guide', 'code' => 301, 'status' => 'active', 'source' => 'migration']);
    $client = fakeConsolidationWp();
    fakeConsolidationOrigin();

    $out = collect(app(LegacyTwinConsolidator::class)->apply($site))->keyBy('from');

    expect($out['/sump-pump-installation-cost-breakdown-8']['redirected'])->toBeFalse()
        ->and($out['/sump-pump-installation-cost-breakdown-8']['note'])->toBe('already redirects to /our-guide — left as is')
        ->and(Redirect::withoutGlobalScopes()->where('from_url', '/sump-pump-installation-cost-breakdown-8')->value('to_url'))->toBe('/our-guide')
        ->and($out->has('/when-to-replace-sump-pump-check-valve'))->toBeFalse()   // the valve group is blocked in the plan, so apply never reaches it
        ->and($out['/sump-pump-installation-cost-breakdown']['removed'])->toBeTrue();
    $client->shouldHaveReceived('retirePost')->once();

    $valve = collect(app(LegacyTwinConsolidator::class)->plan($site)['groups'])->firstWhere('base', '/when-to-replace-sump-pump-check-valve');
    expect($valve['resolvable'])->toBeFalse()->and($valve['reason'])->toBe('keeper-redirects → /our-guide');
});

it('refuses a group whose keeper is not a live page today — a family of dead URLs has nothing to keep', function () {
    $site = twinSite();
    $client = fakeConsolidationWp();
    Http::fake([
        // The whole cost-breakdown chain is gone, keeper included — the Sump Pump Gurus case.
        'spg.example/sump-pump-installation-cost-breakdown-3/*' => Http::response('', 404),
        // The valve keeper redirects live on the site (not in our rows).
        'spg.example/when-to-replace-sump-pump-check-valve-2/*' => Http::response('', 301, ['Location' => 'https://spg.example/sump-pump-replacement/']),
        '*' => Http::response('', 200),
    ]);

    $plan = app(LegacyTwinConsolidator::class)->plan($site);
    $byBase = collect($plan['groups'])->keyBy('base');

    expect($byBase['/sump-pump-installation-cost-breakdown']['resolvable'])->toBeFalse()
        ->and($byBase['/sump-pump-installation-cost-breakdown']['reason'])->toBe('keeper-dead (HTTP 404)')
        ->and($byBase['/sump-pump-installation-cost-breakdown']['keeper_status'])->toBe(404)
        ->and($byBase['/sump-pump-installation-cost-breakdown']['losers'])->toBe([])
        ->and($byBase['/when-to-replace-sump-pump-check-valve']['reason'])->toBe('keeper-redirects → https://spg.example/sump-pump-replacement/')
        ->and($byBase['/when-to-replace-sump-pump-check-valve']['keeper_status'])->toBe(301)
        ->and($plan['totals']['resolvable'])->toBe(0)
        ->and($plan['totals']['redirects'])->toBe(0);

    // Nothing is written for a dead family, however many impressions it still earns.
    expect(app(LegacyTwinConsolidator::class)->apply($site))->toBe([])
        ->and(Redirect::withoutGlobalScopes()->count())->toBe(0);
    $client->shouldNotHaveReceived('retirePost');

    $this->artisan('launchpad:consolidate-legacy-twins --site=SPG')
        // Expectations match output lines in order; the BLOCKED line carries the revival hint itself.
        ->expectsOutputToContain('BLOCKED (keeper-dead (HTTP 404)) /sump-pump-installation-cost-breakdown — nothing live to keep — a family of dead URLs is for the revival flow (launchpad:revive-legacy-content)')
        ->expectsOutputToContain('1 group(s) have no live keeper')
        ->expectsOutputToContain('0 redirect(s) would be written')
        ->assertSuccessful();
});

it('honours --limit and --keep when applying', function () {
    $site = twinSite();
    $client = fakeConsolidationWp();
    Http::fake([
        'spg.example/how-to-install-a-sump-pump-correctly-2/*' => Http::response('', 301, ['Location' => 'https://spg.example/how-to-install-a-sump-pump-correctly-3/']),
        '*' => Http::response('', 200),
    ]);

    // Biggest group first is cost-breakdown; with the install group pinned and limit 1, only cost runs.
    $out = app(LegacyTwinConsolidator::class)->apply($site, keep: ['/how-to-install-a-sump-pump-correctly-3'], limit: 1);
    expect(collect($out)->pluck('base')->unique()->all())->toBe(['/sump-pump-installation-cost-breakdown']);

    // Without a limit the pinned install group is applied too.
    $out = collect(app(LegacyTwinConsolidator::class)->apply($site, keep: ['/how-to-install-a-sump-pump-correctly-3']))->keyBy('from');
    expect($out['/how-to-install-a-sump-pump-correctly-2']['to'])->toBe('/how-to-install-a-sump-pump-correctly-3')
        ->and($out['/how-to-install-a-sump-pump-correctly-2']['removed'])->toBeTrue();
});

it('the command plans by default and applies only with --execute, naming the URLs to purge', function () {
    twinSite();
    fakeConsolidationWp();
    fakeConsolidationOrigin();

    $this->artisan('launchpad:consolidate-legacy-twins --site=SPG')
        ->expectsOutputToContain('Read-only')
        ->expectsOutputToContain('answers HTTP 200')   // the cost keep line (biggest group first)
        ->expectsOutputToContain('301 /sump-pump-installation-cost-breakdown → /sump-pump-installation-cost-breakdown-3')
        ->expectsOutputToContain('301 /sump-pump-installation-cost-breakdown-8 → /sump-pump-installation-cost-breakdown-3 (impr 75,000 (5,000 in 28d) · pos 30 · last seen')
        ->expectsOutputToContain('BLOCKED (ambiguous-earner)')
        ->expectsOutputToContain('3 redirect(s) would be written')
        ->assertSuccessful();
    expect(Redirect::withoutGlobalScopes()->count())->toBe(0);

    $this->artisan('launchpad:consolidate-legacy-twins --site=SPG --execute')
        ->expectsOutputToContain('EXECUTE')
        ->expectsOutputToContain('redirected + retired (trashed)')
        ->expectsOutputToContain('2 redirect(s) verified serving at origin.')
        ->expectsOutputToContain('https://spg.example/sump-pump-installation-cost-breakdown-8/')
        ->assertSuccessful();
    expect(Redirect::withoutGlobalScopes()->count())->toBe(3);
});
