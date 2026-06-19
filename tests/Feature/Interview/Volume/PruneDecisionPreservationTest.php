<?php

use App\Enums\MunicipalityType;
use App\Enums\SpokeGranularity;
use App\Enums\SpokeStatus;
use App\Enums\SpokeTag;
use App\Integrations\DataForSeo\DataForSeoClient;
use App\Integrations\DataForSeo\DataForSeoLocations;
use App\Interview\Volume\VolumeGrounder;
use App\Locations\Dma\DmaTable;
use App\Locations\Dma\MetroResolver;
use App\Models\CoverageArea;
use App\Models\Scopes\SiteScope;
use App\Models\SiloBlueprint;
use App\Models\Site;
use App\Models\Spoke;
use Illuminate\Contracts\Cache\Repository as Cache;
use Illuminate\Http\Client\Factory;
use Illuminate\Support\Facades\Http;

/**
 * §10 — re-grounding the silo volume must NOT wipe the owner's prune decisions. This mirrors
 * the Locations CoverageSelectionTest (page_selected survives recompute): a confirmed
 * own-page choice survives a re-ground that would otherwise recommend folding it, while a
 * still-undecided candidate picks up the fresh recommendation.
 */
function preserveGrounder(): VolumeGrounder
{
    // Every keyword re-grounds to volume 10 — below the 100 bar → the recommendation is "fold".
    Http::fake(function ($request) {
        if (str_contains($request->url(), '/keywords_data/google_ads/locations')) {
            return Http::response(['status_code' => 20000, 'tasks' => [['status_code' => 20000, 'result' => [
                ['location_code' => 1023191, 'location_name' => 'New York NY, United States', 'location_type' => 'DMA Region'],
            ]]]]);
        }
        $kws = $request->data()[0]['keywords'] ?? [];
        $result = array_map(fn ($kw) => ['keyword' => $kw, 'search_volume' => 10], $kws);

        return Http::response(['status_code' => 20000, 'tasks' => [['status_code' => 20000, 'result' => $result]]]);
    });

    $resolver = new MetroResolver(new DmaTable(countyToDma: ['34003' => 'New York,NY,United States'], stateToLocation: []));
    $client = new DataForSeoClient(app(Factory::class), 'x', 'y', 'https://api.dataforseo.com', 30);

    return new VolumeGrounder($resolver, $client, new DataForSeoLocations($client, app(Cache::class), 'US'), 'en');
}

function preserveSpoke(Site $site, string $name): Spoke
{
    return Spoke::withoutGlobalScope(SiteScope::class)->where('site_id', $site->id)->where('name', $name)->first();
}

it('preserves an owner-confirmed own-page choice across a re-ground; new candidates get the fresh recommendation', function () {
    $site = Site::factory()->create(); // no per-site bar → the 100 default applies
    CoverageArea::factory()->create(['site_id' => $site->id, 'geo_id' => '3400312345', 'type' => MunicipalityType::CountySubdivision, 'state' => 'NJ']);
    $bp = SiloBlueprint::factory()->create(['site_id' => $site->id]);

    // owner CONFIRMED this as its own page (status left Candidate) — even though its volume is low
    $locked = Spoke::factory()->create([
        'site_id' => $site->id, 'silo_blueprint_id' => $bp->id, 'silo' => 'Pumps', 'name' => 'Confirmed Own Page',
        'head_keyword' => 'confirmed own page', 'tag' => SpokeTag::Core, 'status' => SpokeStatus::Offered,
        'granularity' => SpokeGranularity::OwnPage,
    ]);
    // a still-undecided candidate — should receive the fresh (fold) recommendation
    $fresh = Spoke::factory()->create([
        'site_id' => $site->id, 'silo_blueprint_id' => $bp->id, 'silo' => 'Pumps', 'name' => 'New Candidate',
        'head_keyword' => 'new candidate', 'tag' => SpokeTag::Core, 'status' => SpokeStatus::Candidate,
        'granularity' => SpokeGranularity::OwnPage,
    ]);

    preserveGrounder()->ground($site);

    $locked = preserveSpoke($site, 'Confirmed Own Page');
    $fresh = preserveSpoke($site, 'New Candidate');

    expect($locked->granularity)->toBe(SpokeGranularity::OwnPage)  // owner choice survives the flip-to-fold recommendation
        ->and($locked->status)->toBe(SpokeStatus::Offered)          // decision untouched
        ->and((int) $locked->volume)->toBe(10)                      // volume still re-grounded
        ->and($fresh->granularity)->toBe(SpokeGranularity::Folded)  // undecided → fresh recommendation applied
        ->and($fresh->status)->toBe(SpokeStatus::Candidate);
});

it('honors a per-site own-page bar override on re-ground', function () {
    $site = Site::factory()->create(['silo_own_page_bar' => 5]); // bar below the grounded volume 10
    CoverageArea::factory()->create(['site_id' => $site->id, 'geo_id' => '3400312345', 'type' => MunicipalityType::CountySubdivision, 'state' => 'NJ']);
    $bp = SiloBlueprint::factory()->create(['site_id' => $site->id]);
    Spoke::factory()->create([
        'site_id' => $site->id, 'silo_blueprint_id' => $bp->id, 'silo' => 'Pumps', 'name' => 'Candidate',
        'head_keyword' => 'candidate', 'tag' => SpokeTag::Core, 'status' => SpokeStatus::Candidate,
        'granularity' => SpokeGranularity::Folded,
    ]);

    preserveGrounder()->ground($site);

    // volume 10 ≥ bar 5 → own page (the per-site override lowered the bar)
    expect(preserveSpoke($site, 'Candidate')->granularity)->toBe(SpokeGranularity::OwnPage);
});
