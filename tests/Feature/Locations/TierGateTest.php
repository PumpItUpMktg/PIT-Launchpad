<?php

use App\Enums\ContentKind;
use App\Enums\PageType;
use App\Enums\SizeTier;
use App\Integrations\Local\LocalSignalProvider;
use App\Integrations\Local\LocalSignals;
use App\Integrations\Local\MockLocalSignalProvider;
use App\Locations\LocalRelevance;
use App\Locations\TierGate;
use App\Metrics\UrlNormalizer;
use App\Models\Content;
use App\Models\CoverageArea;
use App\Models\Location;
use App\Models\PageIndexState;
use App\Models\Site;
use Illuminate\Support\Str;

const TG_HOME = 'https://spg.example';

function tgSite(): Site
{
    return Site::factory()->create(['domain_url' => TG_HOME]);
}

/**
 * A covered town: always a CoverageArea (provides the tier mapping); optionally a built town Content page,
 * optionally indexed and/or IndexNow-submitted.
 *
 * @param  array{built?: bool, indexed?: bool, submittedDaysAgo?: int, selected?: bool}  $opts
 */
function tgTown(Site $site, string $name, ?string $tier, string $marketId, string $geo, array $opts = []): CoverageArea
{
    $cov = CoverageArea::factory()->create([
        'site_id' => $site->id, 'geo_id' => $geo, 'name' => $name, 'size_tier' => $tier,
        'population' => 20000, 'source' => 'county', 'source_location_ids' => [$marketId],
        // A built town is a selected town — so the drip skips it (only reserve towns are drip candidates).
        // Without this a built tier-above town is itself a reserve the drip may graduate on its default
        // mock score, making dripGraduate() counts non-deterministic.
        'page_selected' => ($opts['selected'] ?? false) || ($opts['built'] ?? false),
    ]);

    if ($opts['built'] ?? false) {
        $slug = Str::slug($name);
        $page = Content::factory()->create([
            'site_id' => $site->id, 'kind' => ContentKind::Page, 'page_type' => PageType::Location,
            'location_id' => null, 'parent_location_id' => $marketId, 'title' => $name, 'slug' => $slug,
        ]);
        if (isset($opts['submittedDaysAgo'])) {
            $page->forceFill(['indexnow_submitted_at' => now()->subDays($opts['submittedDaysAgo'])])->save();
        }
        if ($opts['indexed'] ?? false) {
            $url = TG_HOME.'/'.$slug;
            PageIndexState::create(['site_id' => $site->id, 'url' => $url, 'url_normalized' => UrlNormalizer::url($url), 'index_verdict' => 'PASS']);
        }
    }

    return $cov;
}

it('the top non-empty tier is always buildable', function () {
    $site = tgSite();
    $market = Location::factory()->for($site)->create();
    tgTown($site, 'Big City', 'major', $market->id, '01');

    expect(app(TierGate::class)->status($site, $market->id, SizeTier::Major)->buildable)->toBeTrue();
});

it('locks a tier while the tier above is under the indexed threshold, and unlocks at 80%', function () {
    $site = tgSite();
    $market = Location::factory()->for($site)->create();
    // 5 large built pages; index 3 (60%) → medium locked.
    foreach (range(1, 5) as $i) {
        tgTown($site, "Large $i", 'large', $market->id, "L$i", ['built' => true, 'indexed' => $i <= 3]);
    }

    $locked = app(TierGate::class)->status($site, $market->id, SizeTier::Medium);
    expect($locked->buildable)->toBeFalse()
        ->and($locked->reason)->toContain('Large')
        ->and($locked->builtAbove)->toBe(5)
        ->and($locked->indexedAbove)->toBe(3);

    // Index a 4th (80%) → medium unlocks.
    $url = TG_HOME.'/'.Str::slug('Large 4');
    PageIndexState::create(['site_id' => $site->id, 'url' => $url, 'url_normalized' => UrlNormalizer::url($url), 'index_verdict' => 'PASS']);

    expect(app(TierGate::class)->status($site, $market->id, SizeTier::Medium)->buildable)->toBeTrue();
});

it('the time escape unlocks a tier when the last submission above is older than stale_days', function () {
    $site = tgSite();
    $market = Location::factory()->for($site)->create();
    // 2 large built, unindexed, but submitted 31 days ago → medium unlocks despite 0% indexed.
    tgTown($site, 'Large A', 'large', $market->id, 'LA', ['built' => true, 'submittedDaysAgo' => 31]);
    tgTown($site, 'Large B', 'large', $market->id, 'LB', ['built' => true, 'submittedDaysAgo' => 31]);

    $status = app(TierGate::class)->status($site, $market->id, SizeTier::Medium);
    expect($status->buildable)->toBeTrue()
        ->and($status->reason)->toContain('since last Large submission');
});

it('locks with "Build X first" when the tier above exists in coverage but has no built pages', function () {
    $site = tgSite();
    $market = Location::factory()->for($site)->create();
    tgTown($site, 'Large Reserve', 'large', $market->id, 'LR'); // reserve, not built
    tgTown($site, 'Medium One', 'medium', $market->id, 'M1');

    $status = app(TierGate::class)->status($site, $market->id, SizeTier::Medium);
    expect($status->buildable)->toBeFalse()
        ->and($status->reason)->toBe('Build Large first');
});

it('treats ungrouped (null tier) as a band gated behind small', function () {
    $site = tgSite();
    $market = Location::factory()->for($site)->create();
    tgTown($site, 'Small Built', 'small', $market->id, 'S1', ['built' => true, 'indexed' => false]);
    tgTown($site, 'Nowhere', null, $market->id, 'U1');

    // Small not indexed → ungrouped locked.
    expect(app(TierGate::class)->status($site, $market->id, null)->buildable)->toBeFalse();

    // Index the small page → ungrouped unlocks.
    $url = TG_HOME.'/'.Str::slug('Small Built');
    PageIndexState::create(['site_id' => $site->id, 'url' => $url, 'url_normalized' => UrlNormalizer::url($url), 'index_verdict' => 'PASS']);
    expect(app(TierGate::class)->status($site, $market->id, null)->buildable)->toBeTrue();
});

it('gates each market independently', function () {
    $site = tgSite();
    $indexedMarket = Location::factory()->for($site)->create();
    $laggingMarket = Location::factory()->for($site)->create();
    tgTown($site, 'IM Large', 'large', $indexedMarket->id, 'IML', ['built' => true, 'indexed' => true]);
    tgTown($site, 'LM Large', 'large', $laggingMarket->id, 'LML', ['built' => true, 'indexed' => false]);

    $gate = app(TierGate::class);
    expect($gate->status($site, $indexedMarket->id, SizeTier::Medium)->buildable)->toBeTrue()
        ->and($gate->status($site, $laggingMarket->id, SizeTier::Medium)->buildable)->toBeFalse();
});

it('the drip respects the gate: a medium reserve town waits until Large indexes', function () {
    $site = tgSite();
    $market = Location::factory()->for($site)->create();
    tgTown($site, 'Large Page', 'large', $market->id, 'LP', ['built' => true, 'indexed' => false]);
    tgTown($site, 'Medium Reserve', 'medium', $market->id, 'MR');

    /** @var MockLocalSignalProvider $provider */
    $provider = app(LocalSignalProvider::class);
    $provider->set($site->id, 'MR', new LocalSignals('MR', 20000, competitorDensity: 0.0, marketReviewIndex: 1.0, demandIndex: 1.0)); // strong

    // Large not indexed → the strong-signal medium town is NOT graduated.
    expect(app(LocalRelevance::class)->dripGraduate($site))->toBe(0)
        ->and(CoverageArea::where('geo_id', 'MR')->first()->page_selected)->toBeFalse();

    // Index Large → the medium town now graduates.
    $url = TG_HOME.'/'.Str::slug('Large Page');
    PageIndexState::create(['site_id' => $site->id, 'url' => $url, 'url_normalized' => UrlNormalizer::url($url), 'index_verdict' => 'PASS']);

    expect(app(LocalRelevance::class)->dripGraduate($site))->toBe(1)
        ->and(CoverageArea::where('geo_id', 'MR')->first()->page_selected)->toBeTrue();
});

it('never un-selects an operator override in a locked tier (the gate constrains only the drip)', function () {
    $site = tgSite();
    $market = Location::factory()->for($site)->create();
    tgTown($site, 'Large Page', 'large', $market->id, 'LP', ['built' => true, 'indexed' => false]); // locks medium
    tgTown($site, 'Operator Pick', 'medium', $market->id, 'OP', ['selected' => true]); // hand-selected despite lock

    app(LocalRelevance::class)->dripGraduate($site);

    expect(CoverageArea::where('geo_id', 'OP')->first()->page_selected)->toBeTrue();
});
