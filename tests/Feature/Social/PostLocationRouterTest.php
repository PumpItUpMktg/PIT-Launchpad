<?php

use App\Models\Content;
use App\Models\ContentTown;
use App\Models\CoverageArea;
use App\Models\Location;
use App\Models\Site;
use App\Social\PostLocationRouter;

it('resolves a post\'s GBP locations from the towns it names; a post naming none, or a one-location site, means every location', function () {
    $site = Site::factory()->create();
    $north = Location::factory()->create(['site_id' => $site->id, 'name' => 'North']);
    $south = Location::factory()->create(['site_id' => $site->id, 'name' => 'South', 'served_towns' => [['name' => 'Freehold', 'state' => 'NJ']]]);
    CoverageArea::factory()->create(['site_id' => $site->id, 'name' => 'Hackettstown', 'source_location_ids' => [$north->id]]);
    $router = app(PostLocationRouter::class);

    $named = Content::factory()->create(['site_id' => $site->id, 'slug' => 'a']);
    ContentTown::create(['content_id' => $named->id, 'site_id' => $site->id, 'town' => 'hackettstown', 'town_display' => 'Hackettstown']);
    ContentTown::create(['content_id' => $named->id, 'site_id' => $site->id, 'town' => 'freehold', 'town_display' => 'Freehold']);
    expect(collect($router->forPost($named))->sort()->values()->all())->toBe(collect([$north->id, $south->id])->sort()->values()->all());

    $unknownTown = Content::factory()->create(['site_id' => $site->id, 'slug' => 'b']);
    ContentTown::create(['content_id' => $unknownTown->id, 'site_id' => $site->id, 'town' => 'nowhere', 'town_display' => 'Nowhere']);
    expect($router->forPost($unknownTown))->toBeNull();

    $general = Content::factory()->create(['site_id' => $site->id, 'slug' => 'c']);
    expect($router->forPost($general))->toBeNull();
});
