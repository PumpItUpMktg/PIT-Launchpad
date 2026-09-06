<?php

use App\Enums\FeedOrigin;
use App\Models\Keyword;
use App\Models\Market;
use App\Models\Silo;
use App\Models\Site;
use App\Models\Source;

function genFeed(Site $site, string $derivedFrom, array $attrs = []): Source
{
    $createdAt = $attrs['created_at'] ?? null;
    unset($attrs['created_at']);

    $source = Source::factory()->create(array_merge([
        'site_id' => $site->id,
        'origin' => FeedOrigin::Generated->value,
        'derived_from' => $derivedFrom,
        'url' => 'https://news.google.com/'.fake()->uuid(),
        'enabled' => true,
    ], $attrs));

    if ($createdAt !== null) {
        $source->forceFill(['created_at' => $createdAt])->save();
    }

    return $source;
}

it('reports cardinality: current kw×market cost vs the kw-only projection', function () {
    $site = Site::factory()->create(['brand_name' => 'SPG']);
    $silo = Silo::factory()->create(['site_id' => $site->id]);
    $kw = Keyword::factory()->create(['site_id' => $site->id, 'silo_id' => $silo->id, 'query' => 'sump pump repair']);
    Market::factory()->count(3)->create(['site_id' => $site->id]);

    // One keyword × 3 markets = 3 enabled feeds; only 1 ever produced.
    genFeed($site, "kw:{$kw->id}:mkt:a", ['last_item_at' => now()]);
    genFeed($site, "kw:{$kw->id}:mkt:b", ['last_item_at' => null]);
    genFeed($site, "kw:{$kw->id}:mkt:c", ['last_item_at' => null]);

    $this->artisan('launchpad:report-feed-generation')
        ->assertSuccessful()
        ->expectsOutputToContain('kw×market (current): 3 enabled feed(s), 1 producing')
        ->expectsOutputToContain('kw-only (projected): 1 feed(s)')
        ->expectsOutputToContain('kw×county: not computed');
});

it('flags malformed keyword queries and market labels', function () {
    $site = Site::factory()->create();
    $silo = Silo::factory()->create(['site_id' => $site->id]);
    Keyword::factory()->create(['site_id' => $site->id, 'silo_id' => $silo->id, 'query' => 'sump pump replacement 2']); // trailing number
    Keyword::factory()->create(['site_id' => $site->id, 'silo_id' => $silo->id, 'query' => 'drain cleaning']); // fine
    Market::factory()->create(['site_id' => $site->id, 'name' => 'Halls Cross Roads MD', 'region' => 'MD']); // repeats its state
    Market::factory()->create(['site_id' => $site->id, 'name' => 'Austin', 'region' => 'TX']); // fine
    genFeed($site, 'kw:x:mkt:a');

    $this->artisan('launchpad:report-feed-generation')
        ->assertSuccessful()
        ->expectsOutputToContain('malformed — 1 keyword(s) + 1 market(s)')
        ->expectsOutputToContain('keyword: "sump pump replacement 2"')
        ->expectsOutputToContain('market: "Halls Cross Roads MD MD"');
});

it('reports regeneration: enabled vs disabled split and creation-by-month', function () {
    $site = Site::factory()->create();
    genFeed($site, 'kw:x:mkt:a', ['enabled' => true, 'created_at' => '2026-07-18 00:00:00']);
    genFeed($site, 'kw:x:mkt:b', ['enabled' => false, 'created_at' => '2026-07-18 00:00:00']);

    $this->artisan('launchpad:report-feed-generation')
        ->assertSuccessful()
        ->expectsOutputToContain('2 generated feed(s): 1 enabled, 1 disabled (retired)')
        ->expectsOutputToContain('2026-07: 2');
});

it('errors on an unknown --site', function () {
    $this->artisan('launchpad:report-feed-generation', ['--site' => 'nope'])
        ->assertFailed()
        ->expectsOutputToContain('No site matches');
});
