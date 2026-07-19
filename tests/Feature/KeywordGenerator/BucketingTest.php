<?php

use App\KeywordGenerator\Bucketer;
use App\Models\Silo;
use App\Models\Site;

test('a query buckets into the silo whose rule_set includes it', function () {
    $site = Site::factory()->create();

    $plumbing = Silo::factory()->create([
        'site_id' => $site->id,
        'name' => 'Plumbing',
        'rule_set' => ['include_patterns' => ['water heater', 'drain', 'pipe'], 'exclude_patterns' => []],
    ]);
    $hvac = Silo::factory()->create([
        'site_id' => $site->id,
        'name' => 'HVAC',
        'rule_set' => ['include_patterns' => ['furnace', 'air conditioner', 'hvac'], 'exclude_patterns' => []],
    ]);

    $silos = collect([$plumbing, $hvac]);
    $bucketer = new Bucketer;

    expect($bucketer->bucket('water heater repair cost', $silos)->id)->toBe($plumbing->id)
        ->and($bucketer->bucket('furnace not heating', $silos)->id)->toBe($hvac->id);
});

test('an excluded pattern keeps a query out of a silo', function () {
    $site = Site::factory()->create();
    $silo = Silo::factory()->create([
        'site_id' => $site->id,
        'rule_set' => ['include_patterns' => ['water heater'], 'exclude_patterns' => ['commercial']],
    ]);

    expect((new Bucketer)->bucket('commercial water heater install', collect([$silo])))->toBeNull();
});

test('a keyword routes on a seed_term even when include_patterns miss it', function () {
    $site = Site::factory()->create();
    // Guided-silo shape: narrow include (the pillar phrase) + specific seed_terms (spoke heads).
    $crawl = Silo::factory()->create([
        'site_id' => $site->id, 'name' => 'Crawl Space Waterproofing',
        'rule_set' => [
            'include_patterns' => ['crawl space waterproofing'],
            'seed_terms' => ['crawl space encapsulation', 'crawl space sump pump installation'],
            'exclude_patterns' => [],
        ],
    ]);

    // "crawl space sump pump installation" doesn't contain the include phrase, but IS a seed term.
    expect((new Bucketer)->bucket('crawl space sump pump installation', collect([$crawl]))->id)->toBe($crawl->id);
});

test('a contested keyword lands in the silo whose matching term is most specific (longest)', function () {
    $site = Site::factory()->create();
    $sump = Silo::factory()->create([
        'site_id' => $site->id, 'name' => 'Sump Pumps',
        'rule_set' => ['include_patterns' => ['sump pump'], 'seed_terms' => [], 'exclude_patterns' => []],
    ]);
    $crawl = Silo::factory()->create([
        'site_id' => $site->id, 'name' => 'Crawl Space Waterproofing',
        'rule_set' => ['include_patterns' => ['crawl space waterproofing'], 'seed_terms' => ['crawl space sump pump installation'], 'exclude_patterns' => []],
    ]);

    // Matches "sump pump" (Sump Pumps, 9 chars) AND "crawl space sump pump installation" (Crawl, 33) —
    // the longer, more specific match wins.
    expect((new Bucketer)->bucket('crawl space sump pump installation', collect([$sump, $crawl]))->id)->toBe($crawl->id);
});

test('an unbucketed query is the gap signal', function () {
    $site = Site::factory()->create();
    $silo = Silo::factory()->create([
        'site_id' => $site->id,
        'rule_set' => ['include_patterns' => ['plumbing'], 'exclude_patterns' => []],
    ]);

    expect((new Bucketer)->bucket('roof replacement', collect([$silo])))->toBeNull();
});
