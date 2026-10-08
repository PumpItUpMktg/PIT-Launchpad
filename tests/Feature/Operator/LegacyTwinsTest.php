<?php

use App\Enums\ContentStatus;
use App\Enums\UserRole;
use App\Models\Content;
use App\Models\GscUrlDaily;
use App\Models\Site;
use App\Models\User;
use App\Operator\Coverage\LegacyTwins;
use App\Publishing\Redirects\CollisionSuffix;
use App\Support\CurrentSite;
use Illuminate\Support\Str;

beforeEach(function () {
    $this->actingAs(User::factory()->create(['role' => UserRole::Operator]));
});

afterEach(function () {
    CurrentSite::clear();
});

function twinGscUrl(Site $site, string $path, int $impressions, int $daysAgo = 3, float $position = 9.0, int $clicks = 1): void
{
    GscUrlDaily::withoutGlobalScopes()->create([
        'id' => (string) Str::ulid(), 'site_id' => $site->id, 'grain_hash' => Str::random(32),
        'date' => now()->subDays($daysAgo)->toDateString(), 'url' => 'https://spg.example'.$path,
        'impressions' => $impressions, 'clicks' => $clicks, 'position' => $position,
    ]);
}

/**
 * cost-breakdown: the base + -3 + -8, -3 earns most in the window → keeper; the others redirect to it.
 * install: -2 and -3 tie in the window → ambiguous. valve: -2 earned only last year → earner-lifetime.
 * maintenance-101 is a title. A twin of OUR page is SlugCollisions' business, not a legacy twin.
 */
function twinSite(): Site
{
    $site = Site::factory()->create(['brand_name' => 'SPG', 'domain_url' => 'https://spg.example']);
    Content::factory()->post()->create(['site_id' => $site->id, 'status' => ContentStatus::Published, 'slug' => 'our-guide']);

    twinGscUrl($site, '/sump-pump-installation-cost-breakdown/', 1_000, daysAgo: 400, position: 14.0);
    twinGscUrl($site, '/sump-pump-installation-cost-breakdown-3/', 20_000, daysAgo: 2, position: 6.0, clicks: 150);
    twinGscUrl($site, '/sump-pump-installation-cost-breakdown-3', 1_000, daysAgo: 5, position: 6.0); // slash-less form folds in
    twinGscUrl($site, '/sump-pump-installation-cost-breakdown-8/', 70_000, daysAgo: 300, position: 30.0);
    twinGscUrl($site, '/sump-pump-installation-cost-breakdown-8/', 5_000, daysAgo: 4, position: 30.0);

    twinGscUrl($site, '/how-to-install-a-sump-pump-correctly-2/', 500, daysAgo: 1);
    twinGscUrl($site, '/how-to-install-a-sump-pump-correctly-3/', 500, daysAgo: 1);

    twinGscUrl($site, '/when-to-replace-sump-pump-check-valve/', 40, daysAgo: 200);
    twinGscUrl($site, '/when-to-replace-sump-pump-check-valve-2/', 59_516, daysAgo: 200);

    twinGscUrl($site, '/sump-pump-maintenance-101/', 7_073);
    twinGscUrl($site, '/our-guide/', 300);
    twinGscUrl($site, '/our-guide-2/', 900);
    twinGscUrl($site, '/blog/page/2/', 10);

    return $site;
}

it('groups the legacy twins by the title they copy and names the earner on the recent window', function () {
    $r = app(LegacyTwins::class)->for(twinSite());
    $byBase = collect($r['groups'])->keyBy('base');

    expect($byBase->keys()->all())->toBe([
        '/sump-pump-installation-cost-breakdown', '/when-to-replace-sump-pump-check-valve', '/how-to-install-a-sump-pump-correctly',
    ]); // biggest lifetime impressions first; no Maintenance 101, no pagination, no twin of our page

    $cost = $byBase['/sump-pump-installation-cost-breakdown'];
    expect($cost['resolvable'])->toBeTrue()
        ->and($cost['reason'])->toBe('earner')
        ->and($cost['keeper']['path'])->toBe('/sump-pump-installation-cost-breakdown-3')
        ->and($cost['keeper']['impressions'])->toBe(21_000)          // both slash forms folded
        ->and($cost['keeper']['window_impressions'])->toBe(21_000)
        ->and($cost['keeper']['position'])->toBe(6.0)
        ->and($cost['keeper']['last_seen'])->toBe(now()->subDays(2)->toDateString())
        ->and(collect($cost['losers'])->pluck('from')->all())->toBe(['/sump-pump-installation-cost-breakdown', '/sump-pump-installation-cost-breakdown-8'])
        ->and(collect($cost['losers'])->pluck('to')->unique()->all())->toBe(['/sump-pump-installation-cost-breakdown-3'])
        ->and($cost['impressions'])->toBe(97_000)
        ->and($cost['members'][0]['numbered'])->toBeFalse();        // the base leads the member list

    expect($byBase['/how-to-install-a-sump-pump-correctly']['resolvable'])->toBeFalse()
        ->and($byBase['/how-to-install-a-sump-pump-correctly']['reason'])->toBe('ambiguous-earner')
        ->and($byBase['/how-to-install-a-sump-pump-correctly']['losers'])->toBe([]);

    $valve = $byBase['/when-to-replace-sump-pump-check-valve'];
    expect($valve['reason'])->toBe('earner-lifetime')
        ->and($valve['keeper']['path'])->toBe('/when-to-replace-sump-pump-check-valve-2')
        ->and($valve['window_impressions'])->toBe(0);

    expect($r['totals'])->toBe(['groups' => 3, 'twins' => 5, 'resolvable' => 2, 'ambiguous' => 1, 'impressions' => 97_000 + 1_000 + 59_556, 'window_impressions' => 26_000 + 1_000, 'redirects' => 3])
        ->and($r['window_days'])->toBe(28);
});

it('judges the earner on a different window when asked', function () {
    // At 500 days every member counts in the window; -8 (75,000) then outranks -3 (21,000).
    $r = app(LegacyTwins::class)->for(twinSite(), days: 500);

    expect(collect($r['groups'])->firstWhere('base', '/sump-pump-installation-cost-breakdown')['keeper']['path'])
        ->toBe('/sump-pump-installation-cost-breakdown-8');
});

it('strips a collision suffix from a path but never from pagination', function () {
    expect(CollisionSuffix::stripPath('/sump-pump-cost-3'))->toBe('/sump-pump-cost')
        ->and(CollisionSuffix::stripPath('/services/sump-pump-repair-2'))->toBe('/services/sump-pump-repair')
        ->and(CollisionSuffix::stripPath('/blog/page/2'))->toBeNull()
        ->and(CollisionSuffix::stripPath('/sump-pump-maintenance-101'))->toBeNull()
        ->and(CollisionSuffix::stripPath('/sump-pump-cost'))->toBeNull();
});

it('the command reports the groups, the rule, and what a consolidation would redirect', function () {
    twinSite();

    $this->artisan('launchpad:report-legacy-twins --site=SPG')
        ->expectsOutputToContain('legacy numbered twins')
        ->expectsOutputToContain('3 group(s)')
        ->expectsOutputToContain('2 resolvable (the rule names an earner → 3 redirect(s)) · 1 ambiguous')
        ->expectsOutputToContain('ambiguous-earner')
        ->expectsOutputToContain('→ /sump-pump-installation-cost-breakdown-3')
        ->assertSuccessful();
});

it('says none for a site without legacy twins', function () {
    $site = Site::factory()->create(['brand_name' => 'Quiet', 'domain_url' => 'https://spg.example']);
    twinGscUrl($site, '/sump-pump-maintenance-101/', 100);

    $this->artisan('launchpad:report-legacy-twins --site=Quiet')
        ->expectsOutputToContain('None.')
        ->assertSuccessful();
});
