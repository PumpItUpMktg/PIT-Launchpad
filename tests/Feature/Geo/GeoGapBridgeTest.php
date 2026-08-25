<?php

use App\Enums\ContentKind;
use App\Enums\ContentStatus;
use App\Enums\GeoIntent;
use App\Enums\IntakeType;
use App\Geo\GeoGapBridge;
use App\Jobs\BridgeSiteGeoGaps;
use App\Models\Content;
use App\Models\CoverageArea;
use App\Models\GeoPrompt;
use App\Models\GeoSnapshot;
use App\Models\Scopes\SiteScope;
use App\Models\Service;
use App\Models\Silo;
use App\Models\Site;
use App\Support\CurrentSite;
use Illuminate\Database\Eloquent\Collection;

afterEach(fn () => CurrentSite::clear());

/** @return array{0: Site, 1: Service, 2: CoverageArea} */
function bridgeSite(): array
{
    $site = Site::factory()->create(['brand_name' => 'Sump Pump Gurus']);
    $svc = Service::factory()->create(['site_id' => $site->id, 'name' => 'Repair']);
    $town = CoverageArea::factory()->create(['site_id' => $site->id, 'name' => 'Union', 'state' => 'NJ', 'size_tier' => 'major', 'population' => 60000, 'page_selected' => true]);

    return [$site, $svc, $town];
}

function absentGap(Site $site, ?Service $svc, ?CoverageArea $town, array $competitors = ['Rival']): GeoPrompt
{
    $p = GeoPrompt::create([
        'site_id' => $site->id,
        'service_id' => $svc?->id,
        'coverage_area_id' => $town?->id,
        'size_tier' => $town?->size_tier,
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
    [$site, $svc, $town] = bridgeSite();
    $silo = Silo::factory()->create(['site_id' => $site->id]);
    $silo->services()->attach($svc->id);
    $gap = absentGap($site, $svc, $town, ['Rival Plumbing']);

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
        ->and($candidate->meta['geo_gap']['town'])->toBe('Union')
        ->and($candidate->meta['geo_gap']['size_tier'])->toBe('major')
        ->and($candidate->meta['geo_gap']['competitors'])->toBe(['Rival Plumbing']);
});

it('is idempotent — re-running reuses the bridged candidate, never duplicates', function () {
    [$site, $svc, $town] = bridgeSite();
    absentGap($site, $svc, $town);

    (new GeoGapBridge)->bridge($site);
    $second = (new GeoGapBridge)->bridge($site);

    expect($second)->toMatchArray(['created' => 0, 'reused' => 1, 'gaps' => 1])
        ->and(bridged($site))->toHaveCount(1);
});

it('does not bridge a prompt that is already cited (not a gap)', function () {
    [$site, $svc, $town] = bridgeSite();
    $cited = GeoPrompt::create(['site_id' => $site->id, 'service_id' => $svc->id, 'coverage_area_id' => $town->id, 'intent' => GeoIntent::Hire->value, 'prompt' => 'q', 'active' => true]);
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
    [$site, , $town] = bridgeSite();
    absentGap($site, null, $town);   // untagged manual prompt

    expect((new GeoGapBridge)->bridge($site)['gaps'])->toBe(0)
        ->and(bridged($site))->toHaveCount(0);
});

it('leaves the silo null when the service is not wired into the silo tree', function () {
    [$site, $svc, $town] = bridgeSite();   // service exists but no silo mapping
    absentGap($site, $svc, $town);

    (new GeoGapBridge)->bridge($site);

    expect(bridged($site)->sole()->silo_id)->toBeNull();
});

it('bounds the number of gaps bridged per run, biggest-town first', function () {
    config(['launchpad.geo.bridge.max_gaps' => 1]);
    [$site, $svc, $major] = bridgeSite();
    $small = CoverageArea::factory()->create(['site_id' => $site->id, 'name' => 'SmallBoro', 'state' => 'NJ', 'size_tier' => 'small', 'population' => 4000, 'page_selected' => true]);

    // Two absent gaps; only the major-town one should be bridged under the cap of 1.
    $majorGap = absentGap($site, $svc, $major);
    absentGap($site, $svc, $small);

    $r = (new GeoGapBridge)->bridge($site);

    expect($r['created'])->toBe(1);
    expect(bridged($site)->sole()->external_id)->toBe('geo-gap:'.$majorGap->id);
});

it('the bridge-geo-gaps command runs for a site', function () {
    [$site, $svc, $town] = bridgeSite();
    absentGap($site, $svc, $town);

    $this->artisan('sandhog:bridge-geo-gaps', ['site' => $site->id])
        ->expectsOutputToContain('candidate')
        ->assertSuccessful();

    expect(bridged($site))->toHaveCount(1);
});

it('the bridge job runs for a site', function () {
    [$site, $svc, $town] = bridgeSite();
    absentGap($site, $svc, $town);

    (new BridgeSiteGeoGaps((string) $site->id))->handle(new GeoGapBridge);

    expect(bridged($site))->toHaveCount(1);
});
