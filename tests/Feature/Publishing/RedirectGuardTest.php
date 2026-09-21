<?php

use App\Enums\ContentStatus;
use App\Models\Content;
use App\Models\GscUrlDaily;
use App\Models\Redirect;
use App\Models\Site;
use App\Publishing\Redirects\CollisionSuffix;
use App\Publishing\Redirects\GscUrlInventory;
use App\Publishing\Redirects\LegacyContentReviver;
use App\Publishing\Redirects\LegacyRedirectPlanner;
use App\Publishing\Redirects\RedirectGuard;
use App\Support\CurrentSite;
use Illuminate\Support\Str;

afterEach(function () {
    CurrentSite::clear();
});

function guardSearch(Site $site, string $path, int $impressions, float $position): void
{
    GscUrlDaily::withoutGlobalScopes()->create([
        'id' => (string) Str::ulid(),
        'site_id' => $site->id,
        'grain_hash' => Str::random(32),
        'date' => now()->subDays(3)->toDateString(),
        'url' => rtrim((string) $site->domain_url, '/').$path,
        'impressions' => $impressions,
        'clicks' => 1,
        'position' => $position,
    ]);
}

/** @return array{redirect: list<array<string, mixed>>, gone: list, skipped_live: int, unresolved: list} */
function guardPlan(array $redirects): array
{
    return ['redirect' => $redirects, 'gone' => [], 'skipped_live' => 0, 'unresolved' => []];
}

it('flags a redirect that retires the stronger page', function () {
    $site = Site::factory()->create();
    guardSearch($site, '/sump-pump-installation-cost-breakdown-3', 189317, 6.0);
    guardSearch($site, '/sump-pump-installation', 900, 24.0);

    $findings = app(RedirectGuard::class)->check($site, guardPlan([
        ['from' => '/sump-pump-installation-cost-breakdown-3', 'to' => '/sump-pump-installation',
            'code' => 301, 'impressions' => 189317, 'reason' => 'slug_overlap', 'top_query' => null],
    ]));

    expect($findings['outranked'])->toHaveCount(1)
        ->and($findings['outranked'][0]['source_position'])->toBe(6.0)
        ->and($findings['outranked'][0]['target_position'])->toBe(24.0)
        ->and($findings['blocking'])->toBeTrue();
});

it('is content with a redirect onto a stronger successor', function () {
    $site = Site::factory()->create();
    guardSearch($site, '/services/sump-pump-services/sump-pump-repair', 6031, 19.0);
    guardSearch($site, '/sump-pump-maintenance/sump-pump-repair', 40000, 5.0);

    $findings = app(RedirectGuard::class)->check($site, guardPlan([
        ['from' => '/services/sump-pump-services/sump-pump-repair', 'to' => '/sump-pump-maintenance/sump-pump-repair',
            'code' => 301, 'impressions' => 6031, 'reason' => 'slug_overlap', 'top_query' => null],
    ]));

    // The old URL structure retiring onto a better page is the case redirects exist for.
    expect($findings['outranked'])->toBe([])->and($findings['blocking'])->toBeFalse();
});

it('flags a page being asked to absorb a whole section', function () {
    $site = Site::factory()->create();
    guardSearch($site, '/sump-pump-installation', 4000, 9.0);

    $redirects = [];
    foreach (['cost-breakdown', 'how-to-install', 'diy-instructions', 'plumbing-permit'] as $i => $slug) {
        guardSearch($site, '/'.$slug, 50, 40.0);   // quiet enough not to trip the outranked check
        $redirects[] = ['from' => '/'.$slug, 'to' => '/sump-pump-installation', 'code' => 301,
            'impressions' => 1000 * ($i + 1), 'reason' => 'slug_overlap', 'top_query' => null];
    }

    $findings = app(RedirectGuard::class)->check($site, guardPlan($redirects));

    expect($findings['funnels'])->toHaveCount(1)
        ->and($findings['funnels'][0]['sources'])->toBe(4)
        ->and($findings['funnels'][0]['impressions'])->toBe(10000)
        ->and($findings['blocking'])->toBeTrue();
});

it('does not judge a source too quiet to have a meaningful rank', function () {
    $site = Site::factory()->create();
    guardSearch($site, '/barely-seen', 7, 2.0);      // a #2 nobody sees is noise, not evidence
    guardSearch($site, '/successor', 5000, 30.0);

    $findings = app(RedirectGuard::class)->check($site, guardPlan([
        ['from' => '/barely-seen', 'to' => '/successor', 'code' => 301,
            'impressions' => 7, 'reason' => 'slug_overlap', 'top_query' => null],
    ]));

    expect($findings['outranked'])->toBe([]);
});

it('flags a successor Google has never shown at all', function () {
    $site = Site::factory()->create();
    guardSearch($site, '/earning-page', 30000, 8.0);

    $findings = app(RedirectGuard::class)->check($site, guardPlan([
        ['from' => '/earning-page', 'to' => '/brand-new-page', 'code' => 301,
            'impressions' => 30000, 'reason' => 'top_query', 'top_query' => 'sump pump'],
    ]));

    // Nothing is known about the target's ability to hold a ranking, and 30,000 impressions is a lot to
    // find out with.
    expect($findings['outranked'])->toHaveCount(1)
        ->and($findings['outranked'][0]['target_position'])->toBeNull();
});

it('refuses to apply over a finding, and says what to do instead', function () {
    $site = Site::factory()->create(['brand_name' => 'Sump Pump Gurus']);
    $live = Content::factory()->create([
        'site_id' => $site->id, 'slug' => 'sump-pump-installation', 'status' => ContentStatus::Published,
    ]);
    guardSearch($site, '/'.$live->slug, 400, 26.0);
    guardSearch($site, '/sump-pump-installation-cost-breakdown-3', 189317, 6.0);

    $this->artisan('launchpad:plan-legacy-redirects', ['--site' => $site->id, '--apply' => true])
        ->expectsOutputToContain('Retiring the stronger page')
        ->expectsOutputToContain('Not applied')
        ->assertFailed();

    expect(Redirect::withoutGlobalScopes()->count())->toBe(0);
});

it('applies when nothing is flagged', function () {
    $site = Site::factory()->create(['brand_name' => 'Clean Site']);

    $this->artisan('launchpad:plan-legacy-redirects', ['--site' => $site->id, '--apply' => true])
        ->expectsOutputToContain('Checks passed')
        ->assertSuccessful();
});

it('reads a large trailing number as a title, not a duplicate', function () {
    // "Maintenance 101" is an idiom. WordPress collision chains are contiguous from 2, so a -101 with no
    // -2 through -100 was never a copy — and collapsing it retired 7,073 impressions on Sump Pump Gurus.
    expect(CollisionSuffix::strip('sump-pump-maintenance-101'))->toBeNull()
        ->and(CollisionSuffix::strip('best-pumps-2025'))->toBeNull()
        ->and(CollisionSuffix::strip('sump-pump-installation-cost-breakdown-3'))->toBe('sump-pump-installation-cost-breakdown')
        ->and(CollisionSuffix::strip('battery-backup-sump-pump-types-10'))->toBe('battery-backup-sump-pump-types')
        // WordPress starts at 2; a -1 is part of the name.
        ->and(CollisionSuffix::strip('sump-pump-1'))->toBeNull()
        ->and(CollisionSuffix::strip('no-suffix-here'))->toBeNull();
});

it('honours a raised ceiling for a site that really does have long chains', function () {
    config(['launchpad.legacy_redirect.max_collision_suffix' => 200]);

    expect(CollisionSuffix::strip('sump-pump-maintenance-101'))->toBe('sump-pump-maintenance');
});

it('diverts a high-value top_query family to revival instead of redirecting it', function () {
    $site = Site::factory()->create();
    // The live installation page the planner would route "how to install…" onto by top-query match.
    $pillar = Content::factory()->create([
        'site_id' => $site->id, 'slug' => 'sump-pump-installation', 'status' => ContentStatus::Published,
    ]);
    guardSearch($site, '/'.$pillar->slug, 400, 26.0);

    $reviver = app(LegacyContentReviver::class);
    $planner = app(LegacyRedirectPlanner::class);

    $plan = ['redirect' => [
        ['from' => '/how-to-install-a-sump-pump-correctly-2', 'to' => '/sump-pump-installation', 'code' => 301,
            'impressions' => 87949, 'reason' => 'top_query', 'top_query' => 'how to install a sump pump'],
        ['from' => '/how-to-install-a-sump-pump-correctly-3', 'to' => '/sump-pump-installation', 'code' => 301,
            'impressions' => 72008, 'reason' => 'top_query', 'top_query' => 'how to install a sump pump'],
    ], 'gone' => [], 'skipped_live' => 0, 'unresolved' => []];

    // The reviver reads the planner's own output, so drive it through a stub that returns this plan.
    $stub = new class($plan) extends LegacyRedirectPlanner
    {
        public function __construct(private array $stubbed)
        {
            parent::__construct(app(GscUrlInventory::class));
        }

        public function plan(Site $site): array
        {
            return $this->stubbed;
        }
    };

    $families = (new LegacyContentReviver($stub))->plan($site);

    // 159,957 across the family, well over divert_floor — kept as its own post rather than funnelled into
    // a service page that could never rank for "how to install a sump pump".
    expect($families)->toHaveCount(1)
        ->and($families[0]['impressions'])->toBe(159957)
        ->and($families[0]['from_urls'])->toHaveCount(2);

    expect($planner)->toBeInstanceOf(LegacyRedirectPlanner::class);
});
