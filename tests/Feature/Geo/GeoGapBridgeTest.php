<?php

use App\Enums\ContentKind;
use App\Enums\ContentStatus;
use App\Enums\GeoIntent;
use App\Enums\IntakeType;
use App\Geo\GeoGapBridge;
use App\Jobs\BridgeSiteGeoGaps;
use App\Models\Content;
use App\Models\GeoPrompt;
use App\Models\GeoSnapshot;
use App\Models\Market;
use App\Models\Scopes\SiteScope;
use App\Models\Service;
use App\Models\Silo;
use App\Models\Site;
use App\Support\CurrentSite;
use Illuminate\Database\Eloquent\Collection;

afterEach(fn () => CurrentSite::clear());

/** @return array{0: Site, 1: Service, 2: Market} */
function bridgeSite(): array
{
    $site = Site::factory()->create(['brand_name' => 'Sump Pump Gurus']);
    $svc = Service::factory()->create(['site_id' => $site->id, 'name' => 'Repair']);
    $market = Market::factory()->priority()->create(['site_id' => $site->id, 'name' => 'Union']);

    return [$site, $svc, $market];
}

function absentGap(Site $site, ?Service $svc, ?Market $market, array $competitors = ['Rival']): GeoPrompt
{
    $p = GeoPrompt::create([
        'site_id' => $site->id,
        'service_id' => $svc?->id,
        'market_id' => $market?->id,
        'intent' => GeoIntent::Hire->value,
        'prompt' => 'who repairs sump pumps in union nj',
        'active' => true,
        'label' => 'Repair · Hire',
    ]);
    GeoSnapshot::create(['site_id' => $site->id, 'geo_prompt_id' => $p->id, 'engine' => 'claude', 'cited' => false, 'competitors' => $competitors, 'checked_at' => now()]);

    return $p;
}

/** @return Collection<int, Content> */
function bridged(Site $site)
{
    return Content::withoutGlobalScope(SiteScope::class)->where('site_id', $site->id)->get();
}

it('materializes an absent gap into a directed candidate pinned to the service silo', function () {
    [$site, $svc, $market] = bridgeSite();
    $silo = Silo::factory()->create(['site_id' => $site->id]);
    $silo->services()->attach($svc->id);
    $gap = absentGap($site, $svc, $market, ['Rival Plumbing']);

    $r = (new GeoGapBridge)->bridge($site);

    expect($r)->toMatchArray(['created' => 1, 'reused' => 0, 'gaps' => 1]);

    $candidate = bridged($site)->sole();
    expect($candidate->kind)->toBe(ContentKind::Post)
        ->and($candidate->intake_type)->toBe(IntakeType::Directed)
        ->and($candidate->status)->toBe(ContentStatus::Candidate)
        ->and($candidate->silo_id)->toBe($silo->id)
        ->and($candidate->matched_silo_id)->toBe($silo->id)
        ->and($candidate->external_id)->toBe('geo-gap:'.$gap->id)
        ->and($candidate->draft_lane)->toBe('geo')
        ->and($candidate->angle_hint)->toContain('who repairs sump pumps in union nj')
        ->and($candidate->angle_hint)->toContain('Rival Plumbing')
        ->and($candidate->meta['geo_gap']['geo_prompt_id'])->toBe($gap->id)
        ->and($candidate->meta['geo_gap']['competitors'])->toBe(['Rival Plumbing']);
});

it('is idempotent — re-running reuses the bridged candidate, never duplicates', function () {
    [$site, $svc, $market] = bridgeSite();
    absentGap($site, $svc, $market);

    (new GeoGapBridge)->bridge($site);
    $second = (new GeoGapBridge)->bridge($site);

    expect($second)->toMatchArray(['created' => 0, 'reused' => 1, 'gaps' => 1])
        ->and(bridged($site))->toHaveCount(1);
});

it('does not bridge a prompt that is already cited (not a gap)', function () {
    [$site, $svc, $market] = bridgeSite();
    $cited = GeoPrompt::create(['site_id' => $site->id, 'service_id' => $svc->id, 'market_id' => $market->id, 'intent' => GeoIntent::Hire->value, 'prompt' => 'q', 'active' => true]);
    GeoSnapshot::create(['site_id' => $site->id, 'geo_prompt_id' => $cited->id, 'engine' => 'claude', 'cited' => true, 'checked_at' => now()]);

    expect((new GeoGapBridge)->bridge($site))->toMatchArray(['created' => 0, 'gaps' => 0])
        ->and(bridged($site))->toHaveCount(0);
});

it('skips an unmeasured prompt (nothing to re-measure yet)', function () {
    [$site, $svc] = bridgeSite();
    // Active, service-tagged, but never checked — a blind spot, not an absent gap.
    GeoPrompt::create(['site_id' => $site->id, 'service_id' => $svc->id, 'intent' => GeoIntent::Hire->value, 'prompt' => 'q', 'active' => true]);

    expect((new GeoGapBridge)->bridge($site)['gaps'])->toBe(0);
});

it('skips a gap with no service — it can\'t be routed to a silo', function () {
    [$site, , $market] = bridgeSite();
    absentGap($site, null, $market);   // untagged manual prompt

    expect((new GeoGapBridge)->bridge($site)['gaps'])->toBe(0)
        ->and(bridged($site))->toHaveCount(0);
});

it('leaves the silo null when the service is not wired into the silo tree', function () {
    [$site, $svc, $market] = bridgeSite();   // service exists but no silo mapping
    absentGap($site, $svc, $market);

    (new GeoGapBridge)->bridge($site);

    expect(bridged($site)->sole()->silo_id)->toBeNull();
});

it('bounds the number of gaps bridged per run, priority-market first', function () {
    config(['launchpad.geo.bridge.max_gaps' => 1]);
    [$site, $svc, $priority] = bridgeSite();
    $coverage = Market::factory()->coverage()->create(['site_id' => $site->id, 'name' => 'Fringe']);

    // Two absent gaps; only the priority-market one should be bridged under the cap of 1.
    $priorityGap = absentGap($site, $svc, $priority);
    absentGap($site, $svc, $coverage);

    $r = (new GeoGapBridge)->bridge($site);

    expect($r['created'])->toBe(1);
    expect(bridged($site)->sole()->external_id)->toBe('geo-gap:'.$priorityGap->id);
});

it('the bridge-geo-gaps command runs for a site', function () {
    [$site, $svc, $market] = bridgeSite();
    absentGap($site, $svc, $market);

    $this->artisan('sandhog:bridge-geo-gaps', ['site' => $site->id])
        ->expectsOutputToContain('candidate')
        ->assertSuccessful();

    expect(bridged($site))->toHaveCount(1);
});

it('the bridge job runs for a site', function () {
    [$site, $svc, $market] = bridgeSite();
    absentGap($site, $svc, $market);

    (new BridgeSiteGeoGaps((string) $site->id))->handle(new GeoGapBridge);

    expect(bridged($site))->toHaveCount(1);
});
