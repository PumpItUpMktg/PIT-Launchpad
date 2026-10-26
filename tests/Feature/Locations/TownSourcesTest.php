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

it('the government rule: .gov and .us always; .org / .com / .net only when they carry the town\'s name; never a wiki, social, listing or news site', function () {
    expect(TownSourceResolver::isGovernment('www.montclairnjusa.org', ['Montclair']))->toBeTrue()
        ->and(TownSourceResolver::isGovernment('cliftonnj.org', ['Clifton']))->toBeTrue()
        ->and(TownSourceResolver::isGovernment('www.co.essex.nj.us', ['Essex County', 'Essex']))->toBeTrue()
        ->and(TownSourceResolver::isGovernment('trooper.pa.gov', ['Trooper']))->toBeTrue()
        ->and(TownSourceResolver::isGovernment('somerandomsite.org', ['Montclair']))->toBeFalse()
        ->and(TownSourceResolver::isGovernment('en.wikipedia.org', ['Montclair']))->toBeFalse()
        ->and(TownSourceResolver::isGovernment('montclair.patch.com', ['Montclair']))->toBeFalse()
        ->and(TownSourceResolver::isGovernment('www.zillow.com', ['Montclair']))->toBeFalse()
        ->and(TownSourceResolver::isGovernment('montclairplumbers.com', ['Montclair']))->toBeTrue();   // passes the rule; the search query keeps it out in practice
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
