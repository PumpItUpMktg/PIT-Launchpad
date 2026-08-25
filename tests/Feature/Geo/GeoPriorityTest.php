<?php

use App\Enums\GeoIntent;
use App\Enums\GeoPromptPriority;
use App\Geo\GeoGapBridge;
use App\Models\Content;
use App\Models\CoverageArea;
use App\Models\GeoPrompt;
use App\Models\GeoSnapshot;
use App\Models\Scopes\SiteScope;
use App\Models\Service;
use App\Models\Site;
use App\Support\CurrentSite;

afterEach(fn () => CurrentSite::clear());

function priPrompt(Site $site, ?Service $svc, ?CoverageArea $town, string $priority): GeoPrompt
{
    return GeoPrompt::create([
        'site_id' => $site->id, 'service_id' => $svc?->id, 'coverage_area_id' => $town?->id,
        'size_tier' => $town?->size_tier, 'priority' => $priority, 'intent' => GeoIntent::Hire->value,
        'prompt' => 'q '.$priority.' '.($town?->name ?? '-'), 'active' => true,
    ]);
}

function priTown(Site $site, string $name, string $tier): CoverageArea
{
    return CoverageArea::factory()->create(['site_id' => $site->id, 'name' => $name, 'state' => 'NJ', 'size_tier' => $tier, 'population' => 40000, 'page_selected' => true]);
}

it('orders work by operator priority first, then town size tier, then oldest', function () {
    $site = Site::factory()->create();
    $major = priTown($site, 'Major', 'major');
    $small = priTown($site, 'Small', 'small');

    $lowMajor = priPrompt($site, null, $major, GeoPromptPriority::Low->value);
    $highSmall = priPrompt($site, null, $small, GeoPromptPriority::High->value);
    $normalMajor = priPrompt($site, null, $major, GeoPromptPriority::Normal->value);
    $normalSmall = priPrompt($site, null, $small, GeoPromptPriority::Normal->value);

    $ordered = GeoPrompt::withoutGlobalScope(SiteScope::class)->where('site_id', $site->id)
        ->workOrder()->pluck('id')->all();

    // High (even a small town) leads; then Normal (major before small); Low last.
    expect($ordered)->toBe([$highSmall->id, $normalMajor->id, $normalSmall->id, $lowMajor->id]);
});

it('bridges high-priority gaps before lower-priority ones', function () {
    config(['launchpad.geo.bridge.max_gaps' => 1]);
    $site = Site::factory()->create();
    $svc = Service::factory()->create(['site_id' => $site->id, 'name' => 'Repair']);
    $major = priTown($site, 'MajorTown', 'major');
    $small = priTown($site, 'SmallTown', 'small');

    // A NORMAL gap in a MAJOR town vs a HIGH gap in a SMALL town — priority must win over size.
    $normalGap = priPrompt($site, $svc, $major, GeoPromptPriority::Normal->value);
    GeoSnapshot::create(['site_id' => $site->id, 'geo_prompt_id' => $normalGap->id, 'engine' => 'claude', 'cited' => false, 'checked_at' => now()]);
    $highGap = priPrompt($site, $svc, $small, GeoPromptPriority::High->value);
    GeoSnapshot::create(['site_id' => $site->id, 'geo_prompt_id' => $highGap->id, 'engine' => 'claude', 'cited' => false, 'checked_at' => now()]);

    (new GeoGapBridge)->bridge($site);

    $candidate = Content::withoutGlobalScope(SiteScope::class)->where('site_id', $site->id)->sole();
    expect($candidate->external_id)->toBe('geo-gap:'.$highGap->id);
});
