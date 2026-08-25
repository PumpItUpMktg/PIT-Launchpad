<?php

use App\Models\CoverageArea;
use App\Models\GeoPrompt;
use App\Models\GeoSnapshot;
use App\Models\Location;
use App\Models\Scopes\SiteScope;
use App\Models\Service;
use App\Models\Site;
use App\Support\CurrentSite;
use Illuminate\Support\Collection;

afterEach(fn () => CurrentSite::clear());

/** @return array{0: Site, 1: Service} */
function pruneSite(): array
{
    $site = Site::factory()->create(['brand_name' => 'Sump Pump Gurus']);

    return [$site, Service::factory()->create(['site_id' => $site->id, 'name' => 'Repair'])];
}

function pruneTown(Site $site, string $name, string $state, array $locationIds = []): CoverageArea
{
    return CoverageArea::factory()->create(['site_id' => $site->id, 'name' => $name, 'state' => $state, 'size_tier' => 'major', 'population' => 50000, 'page_selected' => true, 'source_location_ids' => $locationIds]);
}

function prunePrompt(Site $site, Service $svc, CoverageArea $town): GeoPrompt
{
    return GeoPrompt::create(['site_id' => $site->id, 'service_id' => $svc->id, 'coverage_area_id' => $town->id, 'prompt' => 'q '.$town->name, 'active' => true]);
}

/** @return Collection<int, string> */
function promptIds(Site $site)
{
    return GeoPrompt::withoutGlobalScope(SiteScope::class)->where('site_id', $site->id)->pluck('id');
}

it('requires a scope (--state or --location)', function () {
    [$site] = pruneSite();

    $this->artisan('sandhog:prune-geo-prompts', ['site' => $site->id])->assertFailed();
});

it('previews by default, then deletes prompts by state with --apply (removing snapshots too)', function () {
    [$site, $svc] = pruneSite();
    $md = prunePrompt($site, $svc, pruneTown($site, 'Havre de Grace', 'MD'));
    GeoSnapshot::create(['site_id' => $site->id, 'geo_prompt_id' => $md->id, 'engine' => 'claude', 'cited' => false, 'checked_at' => now()]);
    $nj = prunePrompt($site, $svc, pruneTown($site, 'Union', 'NJ'));

    // Preview changes nothing.
    $this->artisan('sandhog:prune-geo-prompts', ['site' => $site->id, '--state' => 'MD'])
        ->expectsOutputToContain('Preview')->assertSuccessful();
    expect(promptIds($site))->toHaveCount(2);

    // Apply deletes the MD prompt + its snapshot; NJ survives.
    $this->artisan('sandhog:prune-geo-prompts', ['site' => $site->id, '--state' => 'MD', '--apply' => true])->assertSuccessful();

    expect(promptIds($site)->all())->toBe([$nj->id])
        ->and(GeoSnapshot::withoutGlobalScope(SiteScope::class)->where('geo_prompt_id', $md->id)->count())->toBe(0);
});

it('deletes prompts scoped to a brick-and-mortar shop', function () {
    [$site, $svc] = pruneSite();
    $shop = Location::factory()->create(['site_id' => $site->id, 'name' => 'MD Shop']);
    $inShop = prunePrompt($site, $svc, pruneTown($site, 'Bel Air', 'MD', [$shop->id]));
    $elsewhere = prunePrompt($site, $svc, pruneTown($site, 'Union', 'NJ', ['other']));

    $this->artisan('sandhog:prune-geo-prompts', ['site' => $site->id, '--location' => 'MD Shop', '--apply' => true])->assertSuccessful();

    expect(promptIds($site)->all())->toBe([$elsewhere->id]);
});
