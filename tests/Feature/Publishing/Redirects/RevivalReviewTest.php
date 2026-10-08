<?php

use App\Analytics\Gsc\Grain;
use App\Enums\ContentKind;
use App\Enums\ContentStatus;
use App\Enums\RedirectSource;
use App\Enums\ServiceSiloRole;
use App\Enums\UserRole;
use App\Integrations\Wordpress\WordpressClient;
use App\Integrations\Wordpress\WordpressClientFactory;
use App\Models\Content;
use App\Models\Redirect;
use App\Models\Scopes\SiteScope;
use App\Models\Service;
use App\Models\Silo;
use App\Models\Site;
use App\Models\User;
use App\Publishing\Redirects\RevivalReview;
use App\Support\CurrentSite;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

beforeEach(function () {
    config()->set('launchpad.legacy_revival.min_impressions', 5000);
    config()->set('launchpad.legacy_revival.divert_floor', 20000);
    $this->actingAs(User::factory()->create(['role' => UserRole::Operator]));
});

afterEach(function () {
    CurrentSite::clear();
});

function rrDaily(Site $site, string $path, int $impressions, ?string $query = null): void
{
    $url = 'https://spg.example'.$path;
    DB::table('gsc_url_daily')->insert([
        'id' => (string) Str::ulid(), 'site_id' => $site->id, 'grain_hash' => Grain::hash([$site->id, '2025-06-01', $url]),
        'date' => '2025-06-01', 'url' => $url, 'impressions' => $impressions, 'clicks' => 0, 'ctr' => 0, 'position' => 5,
        'created_at' => now(), 'updated_at' => now(),
    ]);
    if ($query !== null) {
        DB::table('gsc_url_query_daily')->insert([
            'id' => (string) Str::ulid(), 'site_id' => $site->id, 'grain_hash' => Grain::hash([$site->id, '2025-06-01', $url, $query, 'usa', 'DESKTOP']),
            'date' => '2025-06-01', 'url' => $url, 'query' => $query, 'country' => 'usa', 'device' => 'DESKTOP',
            'impressions' => $impressions, 'clicks' => 0, 'ctr' => 0, 'position' => 5,
            'created_at' => now(), 'updated_at' => now(),
        ]);
    }
}

/** The Sump Pump Gurus shapes, one family each. */
function reviewSite(): Site
{
    $site = Site::factory()->create(['brand_name' => 'SPG', 'domain_url' => 'https://spg.example']);
    CurrentSite::set($site->id);
    Service::factory()->create(['site_id' => $site->id, 'name' => 'Sump Pump Services', 'silo_role' => ServiceSiloRole::Pillar]);
    Silo::factory()->create(['site_id' => $site->id, 'name' => 'Sump Pumps', 'rule_set' => ['include_patterns' => ['sump pump', 'sump pit', 'check valve'], 'exclude_patterns' => []]]);
    Silo::factory()->create(['site_id' => $site->id, 'name' => 'Radon', 'rule_set' => ['include_patterns' => ['radon'], 'exclude_patterns' => []]]);

    // A LIVE post that already covers the GPM query.
    Content::factory()->post()->create(['site_id' => $site->id, 'status' => ContentStatus::Published, 'slug' => 'sump-pump-gpm-how-to-size-your-pump-for-your-home', 'title' => 'Sump Pump GPM: How to Size Your Pump for Your Home']);

    rrDaily($site, '/sump-pump-installation-cost-breakdown-3/', 189_317, 'sump pump installation cost');   // clean
    rrDaily($site, '/sump-pump-installation-cost-breakdown-8/', 70_096, 'sump pump installation cost');
    rrDaily($site, '/best-water-sump-pump-flow-rate/', 23_583, 'sump pump gpm');                            // covered → redirect
    rrDaily($site, '/sump-pump-gallons-per-minute-chart/', 6_345, 'sump pump gpm');                         // covered too (shared, but redirect wins)
    rrDaily($site, '/sump-pump-size-matters-choosing-right-one-for-home/', 46_467, 'what size sump pump do i need'); // covered: "size" is in the GPM post
    rrDaily($site, '/check-if-sump-pump-fits-drainage-pipe/', 32_472, 'what size sump pump do i need');     // covered too
    rrDaily($site, '/does-your-home-need-a-second-sump-pump/', 22_491, 'sump pump');                       // rebrief: head term (never "covered")
    rrDaily($site, '/best-types-of-sump-pumps-for-basements-10/', 38_332, 'sump pump design ideas 2025');   // rebrief: dated (the lead of its brief)
    rrDaily($site, '/how-to-choose-a-sump-pump-type-10/', 9_569, 'sump pump design ideas 2025');            // shares a WEAK brief → rebrief, not fold
    rrDaily($site, '/how-to-test-sump-pump-float-switch/', 12_961, 'how to check sump pump float switch');  // clean (the lead of a sound brief)
    rrDaily($site, '/sump-pump-float-switch-testing-guide/', 6_000, 'how to check sump pump float switch'); // fold into it
    rrDaily($site, '/structural-engineer-water-damage-assessment/', 5_902, 'inspectors check for water damage during a structural inspection.'); // rebrief: sentence (+ decide: no silo)
    rrDaily($site, '/springcity/', 7_153, 'sump pump');                                                     // decide: bare slug
    rrDaily($site, '/forever-pump-program-sump-pump/', 7_073, 'sump pump warranty');                        // decide: structural
    rrDaily($site, '/commercial-carwash-oil-separator-requirements/', 23_932, 'car wash oil water separator'); // decide: no silo
    rrDaily($site, '/how-seasonal-changes-affect-indoor-radon-2/', 5_262, 'does weather affect radon levels');  // clean (radon silo)

    return $site;
}

it('reads the revival plan and gives every family one verdict with the reason', function () {
    $r = app(RevivalReview::class)->for(reviewSite());
    $byKey = collect($r['families'])->keyBy('key');

    expect($r['silo_check'])->toBeTrue()
        ->and($byKey['/sump-pump-installation-cost-breakdown']['flags'])->toBe([])
        ->and($byKey['/sump-pump-installation-cost-breakdown']['verdict'])->toBe(RevivalReview::CLEAN)
        ->and($byKey['/how-seasonal-changes-affect-indoor-radon']['verdict'])->toBe(RevivalReview::CLEAN);

    // Covered by the live GPM post — a redirect, not a rewrite, even though the two share a brief. "What
    // size sump pump do i need" is covered too: its topic word, "size", is in the GPM post's title.
    expect($byKey['/best-water-sump-pump-flow-rate']['verdict'])->toBe(RevivalReview::REDIRECT)
        ->and($byKey['/best-water-sump-pump-flow-rate']['covered_by'])->toBe(['title' => 'Sump Pump GPM: How to Size Your Pump for Your Home', 'path' => '/sump-pump-gpm-how-to-size-your-pump-for-your-home'])
        ->and($byKey['/sump-pump-gallons-per-minute-chart']['verdict'])->toBe(RevivalReview::REDIRECT)
        ->and($byKey['/sump-pump-size-matters-choosing-right-one-for-home']['verdict'])->toBe(RevivalReview::REDIRECT)
        ->and($byKey['/check-if-sump-pump-fits-drainage-pipe']['verdict'])->toBe(RevivalReview::REDIRECT);

    // Shared SOUND brief: the bigger family leads, the smaller folds into it.
    expect($byKey['/how-to-test-sump-pump-float-switch']['verdict'])->toBe(RevivalReview::CLEAN)
        ->and($byKey['/sump-pump-float-switch-testing-guide']['verdict'])->toBe(RevivalReview::FOLD)
        ->and($byKey['/sump-pump-float-switch-testing-guide']['lead'])->toBe('/how-to-test-sump-pump-float-switch');
    // A shared WEAK brief is two families that both need rebriefing — folding one into a bad brief helps nobody.
    expect($byKey['/how-to-choose-a-sump-pump-type']['verdict'])->toBe(RevivalReview::REBRIEF)
        ->and($byKey['/how-to-choose-a-sump-pump-type']['lead'])->toBe('/best-types-of-sump-pumps-for-basements');

    // Weak briefs.
    expect($byKey['/does-your-home-need-a-second-sump-pump']['verdict'])->toBe(RevivalReview::REBRIEF)
        ->and($byKey['/does-your-home-need-a-second-sump-pump']['flags'][0])->toContain('head term')
        ->and($byKey['/best-types-of-sump-pumps-for-basements']['verdict'])->toBe(RevivalReview::REBRIEF)
        ->and($byKey['/best-types-of-sump-pumps-for-basements']['flags'][0])->toContain('dated query');

    // A human decides: a bare slug, an offer page, something no silo matches.
    expect($byKey['/springcity']['verdict'])->toBe(RevivalReview::DECIDE)
        ->and(collect($byKey['/springcity']['flags'])->join(' '))->toContain('bare slug')
        ->and($byKey['/forever-pump-program-sump-pump']['verdict'])->toBe(RevivalReview::DECIDE)
        ->and($byKey['/forever-pump-program-sump-pump']['flags'][0])->toContain('offer or structural page (program)')
        ->and($byKey['/commercial-carwash-oil-separator-requirements']['verdict'])->toBe(RevivalReview::DECIDE)
        ->and($byKey['/commercial-carwash-oil-separator-requirements']['flags'][0])->toContain('no silo matches')
        ->and($byKey['/structural-engineer-water-damage-assessment']['verdict'])->toBe(RevivalReview::DECIDE)   // decide outranks rebrief
        ->and(collect($byKey['/structural-engineer-water-damage-assessment']['flags'])->join(' '))->toContain('a sentence, not a query');

    expect($r['counts'])->toBe([RevivalReview::CLEAN => 3, RevivalReview::REDIRECT => 4, RevivalReview::REBRIEF => 3, RevivalReview::DECIDE => 4, RevivalReview::FOLD => 1]);
});

it('skips the footprint check, and says so, when the site has no silo rule_sets', function () {
    $site = Site::factory()->create(['brand_name' => 'Bare', 'domain_url' => 'https://spg.example']);
    CurrentSite::set($site->id);
    rrDaily($site, '/commercial-carwash-oil-separator-requirements/', 23_932, 'car wash oil water separator');

    $r = app(RevivalReview::class)->for($site);

    expect($r['silo_check'])->toBeFalse()
        ->and($r['families'][0]['verdict'])->toBe(RevivalReview::CLEAN);
});

it('--clean revives only the families the review passed', function () {
    $site = reviewSite();

    $this->artisan('launchpad:revive-legacy-content --site=SPG --clean --apply')
        ->expectsOutputToContain('--clean: 3 of 15 family(ies) pass the review; 12 held back')
        ->expectsOutputToContain('Created 3 blog candidate(s)')
        ->assertSuccessful();

    $created = Content::withoutGlobalScope(SiteScope::class)->where('site_id', $site->id)->where('kind', ContentKind::Post->value)->where('status', ContentStatus::Candidate->value)->get();
    $claimed = $created->flatMap(fn (Content $c): array => (array) $c->meta['revived_from_urls'])->all();
    expect($created)->toHaveCount(3)
        ->and($claimed)->toContain('/sump-pump-installation-cost-breakdown-3', '/how-to-test-sump-pump-float-switch', '/how-seasonal-changes-affect-indoor-radon-2')
        ->and($claimed)->not->toContain('/best-water-sump-pump-flow-rate', '/sump-pump-size-matters-choosing-right-one-for-home', '/springcity', '/does-your-home-need-a-second-sump-pump');
});

it('the review command reports, previews the covered redirects, and writes them only with --apply', function () {
    $site = reviewSite();
    $client = Mockery::mock(WordpressClient::class);
    $client->shouldReceive('upsertRedirects')->once()->andReturn([]);
    $factory = Mockery::mock(WordpressClientFactory::class);
    $factory->shouldReceive('forSite')->andReturn($client);
    app()->instance(WordpressClientFactory::class, $factory);

    $this->artisan('launchpad:review-revivals --site=SPG')
        ->expectsOutputToContain('revival plan, reviewed')
        ->expectsOutputToContain('3 clean · 4 covered by a live post (redirect) · 1 share a brief (fold) · 4 need a decision · 3 need a better brief.')
        ->expectsOutputToContain('--clean --limit=15 --apply')
        ->expectsOutputToContain('4 URL(s) want a 301 to the live post that covers them')
        ->assertSuccessful();
    expect(Redirect::withoutGlobalScopes()->count())->toBe(0);

    $this->artisan('launchpad:review-revivals --site=SPG --redirect-covered')
        ->expectsOutputToContain('301 /best-water-sump-pump-flow-rate → /sump-pump-gpm-how-to-size-your-pump-for-your-home')
        ->expectsOutputToContain('Preview — add --apply')
        ->assertSuccessful();
    expect(Redirect::withoutGlobalScopes()->count())->toBe(0);

    $this->artisan('launchpad:review-revivals --site=SPG --redirect-covered --apply')
        ->expectsOutputToContain('Wrote 4 redirect(s) and pushed')
        ->assertSuccessful();
    $rows = Redirect::withoutGlobalScopes()->where('site_id', $site->id)->get()->keyBy('from_url');
    expect($rows)->toHaveCount(4)
        ->and($rows['/best-water-sump-pump-flow-rate']->to_url)->toBe('/sump-pump-gpm-how-to-size-your-pump-for-your-home')
        ->and($rows['/best-water-sump-pump-flow-rate']->source)->toBe(RedirectSource::Migration)
        ->and($rows['/sump-pump-gallons-per-minute-chart']->code)->toBe(301);

    // The planner now treats those URLs as redirected: they leave the revival plan on the next review.
    $after = collect(app(RevivalReview::class)->for($site)['families'])->pluck('key');
    expect($after)->not->toContain('/best-water-sump-pump-flow-rate')->not->toContain('/sump-pump-gallons-per-minute-chart');
});
