<?php

use App\Enums\ContentKind;
use App\Enums\PageType;
use App\Locations\LocationNesting;
use App\Models\Content;
use App\Models\Location;
use App\Models\Scopes\SiteScope;
use App\Models\Site;

/**
 * A town page under a location hub: page_type=location, no own location_id, pinned to a physical
 * Location via parent_location_id (what TownLocationAssigner produces).
 */
function nestingTownPage(Site $site, string $title, string $slug, ?string $parentLocationId): Content
{
    return Content::withoutGlobalScope(SiteScope::class)->create([
        'site_id' => $site->id,
        'kind' => ContentKind::Page,
        'page_type' => PageType::Location,
        'title' => $title,
        'slug' => $slug,
        'version' => 1,
        'parent_location_id' => $parentLocationId,
    ]);
}

function nestingHubPage(Site $site, string $title, string $slug, string $locationId): Content
{
    return Content::withoutGlobalScope(SiteScope::class)->create([
        'site_id' => $site->id,
        'kind' => ContentKind::Page,
        'page_type' => PageType::Location,
        'title' => $title,
        'slug' => $slug,
        'version' => 1,
        'location_id' => $locationId,
    ]);
}

it('nests a town page under its hub — parent pinned + slug rewritten to the full path', function () {
    $site = Site::factory()->create();
    $loc = Location::factory()->create(['site_id' => $site->id, 'name' => 'Montclair office']);
    $hub = nestingHubPage($site, 'Montclair, NJ', 'montclair-nj', $loc->id);
    $town = nestingTownPage($site, 'Springfield', 'springfield', $loc->id);

    app(LocationNesting::class)->nest($site);

    $town->refresh();
    expect($town->parent_content_id)->toBe($hub->id)
        ->and($town->slug)->toBe('montclair-nj/springfield');
});

it('lets duplicate town names coexist under different hubs (the whole point of nesting)', function () {
    $site = Site::factory()->create();
    $montclair = Location::factory()->create(['site_id' => $site->id, 'name' => 'Montclair office']);
    $trooper = Location::factory()->create(['site_id' => $site->id, 'name' => 'Trooper office']);
    nestingHubPage($site, 'Montclair, NJ', 'montclair-nj', $montclair->id);
    nestingHubPage($site, 'Trooper, PA', 'trooper-pa', $trooper->id);

    // Two towns named "Springfield" — flat slugs already disambiguated by the materialize pass.
    $a = nestingTownPage($site, 'Springfield', 'springfield', $montclair->id);
    $b = nestingTownPage($site, 'Springfield', 'springfield-2', $trooper->id);

    app(LocationNesting::class)->nest($site);

    $a->refresh();
    $b->refresh();
    expect($a->slug)->toBe('montclair-nj/springfield')
        ->and($b->slug)->toBe('trooper-pa/springfield')
        ->and($a->slug)->not->toBe($b->slug);
});

it('is idempotent — re-nesting keeps the same nested slug and parent', function () {
    $site = Site::factory()->create();
    $loc = Location::factory()->create(['site_id' => $site->id, 'name' => 'Trooper office']);
    $hub = nestingHubPage($site, 'Trooper, PA', 'trooper-pa', $loc->id);
    $town = nestingTownPage($site, 'Norristown', 'norristown', $loc->id);

    app(LocationNesting::class)->nest($site);
    app(LocationNesting::class)->nest($site);
    app(LocationNesting::class)->nest($site);

    $town->refresh();
    expect($town->slug)->toBe('trooper-pa/norristown')
        ->and($town->parent_content_id)->toBe($hub->id);
});

it('leaves a town flat when its hub is missing (nothing to nest under)', function () {
    $site = Site::factory()->create();
    $loc = Location::factory()->create(['site_id' => $site->id, 'name' => 'Ghost office']);
    // No hub page for this location.
    $town = nestingTownPage($site, 'Nowhere', 'nowhere', $loc->id);

    app(LocationNesting::class)->nest($site);

    $town->refresh();
    expect($town->slug)->toBe('nowhere')
        ->and($town->parent_content_id)->toBeNull();
});
