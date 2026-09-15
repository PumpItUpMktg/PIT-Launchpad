<?php

use App\Enums\UserRole;
use App\Filament\Pages\TownRankPage;
use App\Models\CoverageArea;
use App\Models\Location;
use App\Models\Site;
use App\Models\User;
use App\TownRank\TownRankKeywords;
use App\TownRank\TownRankPoints;
use Illuminate\Support\Facades\DB;
use Livewire\Livewire;

function trpSite(int $towns = 3): Site
{
    $site = Site::factory()->create(['brand_name' => 'SPG', 'domain_url' => 'https://spg.com']);
    $a = Location::factory()->create(['site_id' => $site->id, 'lat' => 40.0, 'lng' => -74.0]);
    $b = Location::factory()->create(['site_id' => $site->id, 'name' => 'Second', 'lat' => 41.0, 'lng' => -74.5]);
    for ($i = 0; $i < $towns; $i++) {
        CoverageArea::factory()->create(['site_id' => $site->id, 'name' => "Town {$i}", 'population' => 100 + $i, 'lat' => 40.0 + $i / 100, 'lng' => -74.0, 'source_location_ids' => [$i % 2 === 0 ? $a->id : $b->id]]);
    }

    return $site;
}

/** Queries against coverage_areas issued while $fn runs. */
function coverageAreaQueries(callable $fn): int
{
    DB::flushQueryLog();
    DB::enableQueryLog();
    $fn();
    $n = collect(DB::getQueryLog())->filter(fn (array $q): bool => str_contains($q['query'], 'coverage_areas'))->count();
    DB::disableQueryLog();

    return $n;
}

it('builds the town list from one coverage-area load and memoises it for the rest of the request', function () {
    $site = trpSite();
    $points = app(TownRankPoints::class);

    expect(app(TownRankPoints::class))->toBe($points);   // scoped: every caller in the request shares the memo

    $first = coverageAreaQueries(fn () => $points->forSite($site));
    $second = coverageAreaQueries(fn () => $points->forSite($site));

    expect($first)->toBeGreaterThanOrEqual(1)
        ->and(array_column($points->forSite($site), 'name'))->toBe(['Town 2', 'Town 1', 'Town 0'])   // population-desc across both locations
        ->and($second)->toBe(0);

    $points->forget();
    expect(coverageAreaQueries(fn () => $points->forSite($site)))->toBeGreaterThanOrEqual(1);
});

it('renders a card wall of several keywords with a single town-list build', function () {
    $this->actingAs(User::factory()->create(['role' => UserRole::Operator]));
    $site = trpSite();
    $keywords = app(TownRankKeywords::class);
    foreach (['sump pump repair', 'mold remediation', 'crawl space encapsulation'] as $q) {
        $keywords->track($site, $q);
    }
    app(TownRankPoints::class)->forget();

    $n = coverageAreaQueries(fn () => Livewire::test(TownRankPage::class)->set('siteId', $site->id)->assertSee('mold remediation'));

    // The map coords + three keyword reports all read the same memo: the area table is walked once for the
    // list itself (plus the small id→state lookup it makes), never once per card.
    expect($n)->toBeLessThanOrEqual(2);
});
