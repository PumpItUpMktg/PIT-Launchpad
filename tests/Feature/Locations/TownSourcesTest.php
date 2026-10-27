<?php

use App\Console\Commands\ResolveTownSourcesCommand;
use App\Enums\MunicipalityType;
use App\Integrations\Census\County;
use App\Integrations\Census\MockMunicipalityGazetteer;
use App\Integrations\Census\MunicipalityGazetteer;
use App\Integrations\Serp\MockSerpProvider;
use App\Integrations\Serp\SerpProvider;
use App\Integrations\Serp\SerpResult;
use App\Integrations\Serp\SerpResultSet;
use App\Jobs\ResolveTownSources;
use App\Locations\Sources\TownSourceResolver;
use App\Models\CoverageArea;
use App\Models\Site;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;

function townArea(Site $site, string $name, string $geo, array $o = []): CoverageArea
{
    return CoverageArea::factory()->create(array_merge(['site_id' => $site->id, 'name' => $name, 'state' => 'NJ', 'type' => MunicipalityType::Place, 'geo_id' => $geo, 'lat' => 40.8, 'lng' => -74.2, 'source_location_ids' => []], $o));
}

function townSerp(): MockSerpProvider
{
    $serp = new MockSerpProvider;
    $serp->setResults('Montclair NJ official website', new SerpResultSet('Montclair NJ official website', [
        new SerpResult(1, 'https://en.wikipedia.org/wiki/Montclair,_New_Jersey', 'en.wikipedia.org'),
        new SerpResult(2, 'https://patch.com/new-jersey/montclair', 'patch.com'),
        new SerpResult(3, 'https://www.montclairnjusa.org/government/departments', 'www.montclairnjusa.org'),   // the township, deep page
    ]));
    $serp->setResults('Essex County NJ official website', new SerpResultSet('Essex County NJ official website', [
        new SerpResult(1, 'https://www.essexcountynj.org/', 'www.essexcountynj.org'),
    ]));
    $serp->setResults('Nowhere NJ official website', new SerpResultSet('Nowhere NJ official website', [
        new SerpResult(1, 'https://www.zillow.com/nowhere-nj/', 'www.zillow.com'),
        new SerpResult(2, 'https://nowherenj.org/', 'nowherenj.org'),      // right name, but dead
    ]));
    $serp->setResults('Verona NJ official website', new SerpResultSet('Verona NJ official website', [
        new SerpResult(1, 'https://www.veronanj.org/', 'www.veronanj.org'),
    ]));
    app()->instance(SerpProvider::class, $serp);

    return $serp;
}

it('the slot rules: a municipality must carry the town\'s name even on .gov; a county is a .gov/.us that is not a township, or a name-bearing "county" site; state portals, wikis, listings and news never', function () {
    // Municipality slot
    expect(TownSourceResolver::isMunicipalHost('www.montclairnjusa.org', ['Montclair']))->toBeTrue()
        ->and(TownSourceResolver::isMunicipalHost('cliftonnj.org', ['Clifton']))->toBeTrue()
        ->and(TownSourceResolver::isMunicipalHost('www.abingtonpa.gov', ['Abington']))->toBeTrue()
        ->and(TownSourceResolver::isMunicipalHost('aberdeennj.qscend.com', ['Aberdeen']))->toBeTrue()
        ->and(TownSourceResolver::isMunicipalHost('harfordcountymd.gov', ['Abingdon']))->toBeFalse()       // the county, not the town
        ->and(TownSourceResolver::isMunicipalHost('www.allentownpa.gov', ['Alburtis']))->toBeFalse()      // another town's .gov
        ->and(TownSourceResolver::isMunicipalHost('www.pa.gov', ['Allen']))->toBeFalse()                  // the state portal
        ->and(TownSourceResolver::isMunicipalHost('www.state.nj.us', ['Allen']))->toBeFalse()
        ->and(TownSourceResolver::isMunicipalHost('harfordcountymd.gov', ['Harford County']))->toBeTrue()  // a "town" that is the county itself
        ->and(TownSourceResolver::isMunicipalHost('somerandomsite.org', ['Montclair']))->toBeFalse()
        ->and(TownSourceResolver::isMunicipalHost('en.wikipedia.org', ['Montclair']))->toBeFalse()
        ->and(TownSourceResolver::isMunicipalHost('montclair.patch.com', ['Montclair']))->toBeFalse()
        ->and(TownSourceResolver::isMunicipalHost('www.zillow.com', ['Montclair']))->toBeFalse();
    // County slot
    expect(TownSourceResolver::isCountyHost('www.co.essex.nj.us', 'Essex'))->toBeTrue()
        ->and(TownSourceResolver::isCountyHost('norcopa.gov', 'Northampton', 1))->toBeTrue()              // a .gov that abbreviates — only as the top result
        ->and(TownSourceResolver::isCountyHost('norcopa.gov', 'Northampton', 3))->toBeFalse()
        ->and(TownSourceResolver::isCountyHost('www.monmouthcountyclerk.gov', 'Monmouth', 2))->toBeTrue()
        ->and(TownSourceResolver::isCountyHost('www.lehighcounty.org', 'Lehigh'))->toBeTrue()
        ->and(TownSourceResolver::isCountyHost('www.lehightownship.com', 'Lehigh'))->toBeFalse()         // a township, not the county
        ->and(TownSourceResolver::isCountyHost('www.abingtonpa.gov', 'Montgomery', 2))->toBeFalse()      // a town's .gov below the top result
        ->and(TownSourceResolver::isCountyHost('www.pa.gov', 'Lehigh'))->toBeFalse()
        ->and(TownSourceResolver::isCountyHost('lehigh.com', 'Lehigh'))->toBeFalse();                    // name without "county"
    expect(TownSourceResolver::isStatePortal('nj.gov'))->toBeTrue()->and(TownSourceResolver::isStatePortal('www.state.pa.us'))->toBeTrue()->and(TownSourceResolver::isStatePortal('norcopa.gov'))->toBeFalse();
});

it('resolves a town\'s sources: the municipality (home page, checked live), the county (found once, reused by its towns), the flood-map search — and leaves off what fails', function () {
    townSerp();
    Http::fake([
        'https://www.montclairnjusa.org/' => Http::response('ok'),
        'https://www.essexcountynj.org/' => Http::response('ok'),
        'https://www.veronanj.org/' => Http::response('ok'),
        'https://nowherenj.org/' => Http::response('', 503),
    ]);
    app()->instance(MunicipalityGazetteer::class, new MockMunicipalityGazetteer(counties: [new County('34013', 'Essex County', '34', '013')]));
    $site = Site::factory()->create();
    $montclair = townArea($site, 'Montclair', '3401347500');
    $verona = townArea($site, 'Verona', '3401375890');
    $nowhere = townArea($site, 'Nowhere', '3401399999');

    $resolver = app(TownSourceResolver::class);
    $sources = $resolver->resolve($montclair);
    expect(array_column($sources, 'key'))->toBe(['municipality', 'county', 'flood_map'])
        ->and($sources[0]['url'])->toBe('https://www.montclairnjusa.org/')                     // the home page, not the deep result; wiki + patch skipped
        ->and($sources[0]['label'])->toBe('Montclair, NJ — official municipal website')
        ->and($sources[1]['url'])->toBe('https://www.essexcountynj.org/')
        ->and($sources[2]['url'])->toBe('https://msc.fema.gov/portal/search?AddressQuery=Montclair%2C%20NJ');
    expect($montclair->fresh()->local_sources_resolved_at)->not->toBeNull();

    // Verona is in the same county: the county source is reused from Montclair — no county search.
    $resolver->resolve($verona);
    $v = $verona->fresh()->local_sources;
    expect(array_column($v, 'key'))->toBe(['municipality', 'county', 'flood_map'])->and($v[1]['url'])->toBe('https://www.essexcountynj.org/');
    Http::assertSentCount(3);   // montclair, essex, verona — the county checked once

    // Nowhere: the listing site fails the rule, the right-named site is dead → no municipality; county + flood map only.
    $n = $resolver->resolve($nowhere);
    expect(array_column($n, 'key'))->toBe(['county', 'flood_map']);
    expect(TownSourceResolver::forPage($nowhere->fresh()))->toHaveCount(2)
        ->and(TownSourceResolver::forPage(null))->toBe([]);
});

it('re-validation drops a pick the current rules reject and re-resolves only that town; a result served over http is cited at its https front door', function () {
    Http::fake([
        'https://www.delcopa.gov/' => Http::response('ok'),
        'https://www.aldanpa.gov/' => Http::response('ok'),
        'https://www.lehighcounty.org/' => Http::response('ok'),
        'https://www.alburtispa.org/' => Http::response('ok'),
    ]);
    $serp = new MockSerpProvider;
    $serp->setResults('Aldan PA official website', new SerpResultSet('Aldan PA official website', [new SerpResult(1, 'http://www.aldanpa.gov/departments', 'www.aldanpa.gov')]));
    $serp->setResults('Delaware County PA official website', new SerpResultSet('Delaware County PA official website', [new SerpResult(1, 'http://www.delcopa.gov/', 'www.delcopa.gov')]));
    $serp->setResults('Alburtis PA official website', new SerpResultSet('Alburtis PA official website', [new SerpResult(1, 'https://www.allentownpa.gov/', 'www.allentownpa.gov'), new SerpResult(2, 'https://www.alburtispa.org/', 'www.alburtispa.org')]));
    $serp->setResults('Lehigh County PA official website', new SerpResultSet('Lehigh County PA official website', [new SerpResult(1, 'https://www.lehightownship.com/', 'www.lehightownship.com'), new SerpResult(2, 'https://www.lehighcounty.org/', 'www.lehighcounty.org')]));
    app()->instance(SerpProvider::class, $serp);
    app()->instance(MunicipalityGazetteer::class, new MockMunicipalityGazetteer(counties: [new County('42045', 'Delaware County', '42', '045'), new County('42077', 'Lehigh County', '42', '077')]));
    $site = Site::factory()->create();
    $aldan = townArea($site, 'Aldan', '4204500924', ['state' => 'PA']);
    $alburtis = townArea($site, 'Alburtis', '4207700764', ['state' => 'PA', 'local_sources' => [
        ['key' => 'municipality', 'label' => 'Alburtis, PA — official municipal website', 'url' => 'https://www.allentownpa.gov/', 'verified_at' => now()->toIso8601String()],   // wrong under the new rule
        ['key' => 'county', 'label' => 'Lehigh County, PA — official website', 'url' => 'https://www.lehightownship.com/', 'verified_at' => now()->toIso8601String()],     // wrong under the new rule
        ['key' => 'flood_map', 'label' => 'FEMA flood map search for Alburtis, PA', 'url' => 'https://msc.fema.gov/portal/search?AddressQuery=Alburtis%2C%20PA', 'verified_at' => now()->toIso8601String()],
    ], 'local_sources_resolved_at' => now()]);
    $good = townArea($site, 'Good', '4204599999', ['state' => 'PA', 'local_sources' => [
        ['key' => 'municipality', 'label' => 'Good, PA — official municipal website', 'url' => 'https://www.goodpa.gov/', 'verified_at' => now()->toIso8601String()],
    ], 'local_sources_resolved_at' => now()]);

    // http results are cited at their https front door.
    $resolver = app(TownSourceResolver::class);
    $sources = $resolver->resolve($aldan);
    expect($sources[0]['url'])->toBe('https://www.aldanpa.gov/')->and($sources[1]['url'])->toBe('https://www.delcopa.gov/');

    // Re-validation: the good town is untouched; Alburtis loses both wrong picks and is re-resolved to the right ones.
    expect($resolver->revalidate($good))->toBeFalse()->and($good->fresh()->local_sources)->toHaveCount(1);
    $this->artisan(ResolveTownSourcesCommand::class, ['--site' => $site->id, '--revalidate' => true])
        ->expectsOutputToContain('Re-validated 3 town(s); 1 lost a pick under the current rules and were re-resolved.')
        ->assertSuccessful();
    $a = $alburtis->fresh()->local_sources;
    expect(array_column($a, 'key'))->toBe(['municipality', 'county', 'flood_map'])
        ->and($a[0]['url'])->toBe('https://www.alburtispa.org/')
        ->and($a[1]['url'])->toBe('https://www.lehighcounty.org/');
});

it('the job works unresolved towns first in chunks and chains itself; the command reports each town', function () {
    townSerp();
    Http::fake(['*' => Http::response('ok')]);
    app()->instance(MunicipalityGazetteer::class, new MockMunicipalityGazetteer(counties: [new County('34013', 'Essex County', '34', '013')]));
    $site = Site::factory()->create();
    $done = townArea($site, 'Done', '3401300001', ['local_sources' => [], 'local_sources_resolved_at' => now()]);
    $montclair = townArea($site, 'Montclair', '3401347500', ['page_selected' => true]);
    $verona = townArea($site, 'Verona', '3401375890');

    expect(array_map(fn ($a) => $a->name, ResolveTownSources::pending($site->id, 10)))->toBe(['Montclair', 'Verona'])          // unresolved, live-page towns first
        ->and(ResolveTownSources::pending($site->id, 10, true))->toHaveCount(3);                                                   // --force includes the resolved one

    Queue::fake();
    (new ResolveTownSources($site->id, 40))->handle(app(TownSourceResolver::class));
    expect($montclair->fresh()->local_sources_resolved_at)->not->toBeNull()->and($verona->fresh()->local_sources_resolved_at)->not->toBeNull();
    Queue::assertNotPushed(ResolveTownSources::class);   // fewer than a chunk remained → nothing to chain

    $this->artisan(ResolveTownSourcesCommand::class, ['--site' => $site->id])->expectsOutputToContain('every town is resolved')->assertSuccessful();
    $this->artisan(ResolveTownSourcesCommand::class, ['--site' => $site->id, '--force' => true, '--limit' => 1])
        ->expectsOutputToContain('resolving 1 town(s)')
        ->assertSuccessful();
    $this->artisan(ResolveTownSourcesCommand::class, ['--site' => $site->id, '--force' => true, '--queue' => true])->expectsOutputToContain('queued on the worker')->assertSuccessful();
    Queue::assertPushed(ResolveTownSources::class, fn (ResolveTownSources $j) => $j->siteId === $site->id && $j->force === true);
});
