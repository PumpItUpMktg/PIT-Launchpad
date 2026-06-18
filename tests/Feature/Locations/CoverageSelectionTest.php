<?php

use App\Enums\MunicipalityType;
use App\Locations\BaseCoverage;
use App\Locations\CoverageMunicipality;
use App\Locations\CoverageResult;
use App\Locations\CoverageWriter;
use App\Models\CoverageArea;
use App\Models\Location;
use App\Models\Scopes\SiteScope;
use App\Models\Site;

/** A 3-town county result: one Major, one Medium, one ungrouped (null population). */
function tierResult(string $locId): CoverageResult
{
    $a = (new CoverageMunicipality('3401305580', 'Belleville', MunicipalityType::CountySubdivision, 'NJ', 40.79, -74.15, 0.0, [$locId]))->withPopulation(60000);
    $b = (new CoverageMunicipality('3401351210', 'Montclair', MunicipalityType::CountySubdivision, 'NJ', 40.82, -74.21, 0.0, [$locId]))->withPopulation(18000);
    $c = (new CoverageMunicipality('3401321840', 'Essex Fells', MunicipalityType::CountySubdivision, 'NJ', 40.82, -74.28, 0.0, [$locId]))->withPopulation(null);
    $union = [$a, $b, $c];

    return new CoverageResult([new BaseCoverage($locId, 'HQ', 0, $union)], $union);
}

function csAreas(Site $site)
{
    return CoverageArea::withoutGlobalScope(SiteScope::class)->where('site_id', $site->id);
}

test('writer derives size_tier from population at the tenant thresholds', function () {
    $site = Site::factory()->create();
    $loc = Location::factory()->create(['site_id' => $site->id]);

    (new CoverageWriter)->write($site, tierResult($loc->id));

    expect(csAreas($site)->where('geo_id', '3401305580')->value('size_tier'))->toBe('major')   // 60k
        ->and(csAreas($site)->where('geo_id', '3401351210')->value('size_tier'))->toBe('medium') // 18k
        ->and(csAreas($site)->where('geo_id', '3401321840')->value('size_tier'))->toBeNull();     // no population
});

test('page_selected survives a recompute; new towns default unselected', function () {
    $site = Site::factory()->create();
    $loc = Location::factory()->create(['site_id' => $site->id]);
    $writer = new CoverageWriter;

    $writer->write($site, tierResult($loc->id));
    // operator selects Belleville into the drip pool
    csAreas($site)->where('geo_id', '3401305580')->update(['page_selected' => true]);

    // recompute (e.g. a county toggle) — must NOT wipe the selection
    $writer->write($site, tierResult($loc->id));

    expect(csAreas($site)->where('geo_id', '3401305580')->value('page_selected'))->toBe(true)
        ->and(csAreas($site)->where('geo_id', '3401351210')->value('page_selected'))->toBe(false);
});

test('a town whose county is removed drops out (selection goes with it)', function () {
    $site = Site::factory()->create();
    $loc = Location::factory()->create(['site_id' => $site->id]);
    $writer = new CoverageWriter;

    $writer->write($site, tierResult($loc->id));
    csAreas($site)->update(['page_selected' => true]);

    // county removed → only one town remains in the union
    $remaining = (new CoverageMunicipality('3401305580', 'Belleville', MunicipalityType::CountySubdivision, 'NJ', 40.79, -74.15, 0.0, [$loc->id]))->withPopulation(60000);
    $writer->write($site, new CoverageResult([new BaseCoverage($loc->id, 'HQ', 0, [$remaining])], [$remaining]));

    expect(csAreas($site)->count())->toBe(1)
        ->and(csAreas($site)->where('geo_id', '3401351210')->exists())->toBeFalse();
});

test('re-tiering picks up a changed per-site threshold without ACS calls', function () {
    $site = Site::factory()->create();
    $loc = Location::factory()->create(['site_id' => $site->id]);
    $writer = new CoverageWriter;

    $writer->write($site, tierResult($loc->id));
    expect(csAreas($site)->where('geo_id', '3401351210')->value('size_tier'))->toBe('medium'); // 18k @ default

    // raise the Medium floor above 18k → Montclair re-tiers to Small on the next write
    $site->forceFill(['coverage_thresholds' => ['major' => 50000, 'large' => 30000, 'medium' => 20000]])->save();
    $writer->write($site->refresh(), tierResult($loc->id));

    expect(csAreas($site)->where('geo_id', '3401351210')->value('size_tier'))->toBe('small');
});
