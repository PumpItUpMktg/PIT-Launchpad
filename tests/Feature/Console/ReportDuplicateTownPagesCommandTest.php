<?php

use App\Enums\ContentKind;
use App\Enums\ContentStatus;
use App\Enums\PageType;
use App\Models\Content;
use App\Models\Location;
use App\Models\PageIndexState;
use App\Models\Site;

/** A published Location page (town page when $locationId is null; market-landing/hub when set). */
function dupTownPage(Site $site, string $title, string $slug, ?string $parentId, ?string $locationId = null): Content
{
    return Content::factory()->create([
        'site_id' => $site->id,
        'kind' => ContentKind::Page,
        'page_type' => PageType::Location,
        'status' => ContentStatus::Published,
        'title' => $title,
        'slug' => $slug,
        'location_id' => $locationId,
        'parent_location_id' => $parentId,
    ]);
}

it('classifies same-name groups and flags only same-market duplicates', function () {
    $site = Site::factory()->create(['domain_url' => 'https://spg.example']);
    $marketA = Location::factory()->create(['site_id' => $site->id]);
    $marketB = Location::factory()->create(['site_id' => $site->id]);
    $hoboken = Location::factory()->create(['site_id' => $site->id]);

    // (1) SAME-MARKET DUPLICATE: two Trenton town pages under the same parent, both published.
    $keep = dupTownPage($site, 'Trenton, NJ', 'trenton-nj', $marketA->id);
    dupTownPage($site, 'Trenton, NJ', 'trenton-nj-2', $marketA->id);
    PageIndexState::create(['site_id' => $site->id, 'content_id' => $keep->id, 'url' => 'https://spg.example/trenton-nj/', 'url_normalized' => '/trenton-nj', 'index_verdict' => 'PASS']);

    // (2) CROSS-MARKET same-name: two Middletown town pages under different parents.
    dupTownPage($site, 'Middletown, NJ', 'middletown-a', $marketA->id);
    dupTownPage($site, 'Middletown, NJ', 'middletown-b', $marketB->id);

    // (3) MARKET LANDING + town: Hoboken's own hub landing (location_id set) + a Hoboken town page under
    // a different market — legitimately distinct, NOT a duplicate.
    dupTownPage($site, 'Hoboken, NJ', 'hoboken-nj', null, $hoboken->id);          // the market landing
    dupTownPage($site, 'Hoboken, NJ', 'jersey-city-nj/hoboken', $marketB->id);    // a town under market B

    $this->artisan('launchpad:report-duplicate-town-pages')
        ->assertSuccessful()
        ->expectsOutputToContain('SAME-MARKET DUPLICATE — resolve')
        ->expectsOutputToContain('cross-market same-name — review')
        ->expectsOutputToContain('market landing + town — distinct by design')
        ->expectsOutputToContain('1 same-market duplicate town(s) to resolve (1 extra live page(s)); 1 cross-market same-name to review; 1 landing+town');
});

it('shows a three-state index verdict, including "not yet checked" for an un-inspected page', function () {
    $site = Site::factory()->create(['domain_url' => 'https://spg.example']);
    $market = Location::factory()->create(['site_id' => $site->id]);

    $indexed = dupTownPage($site, 'Clifton, NJ', 'clifton-nj', $market->id);
    dupTownPage($site, 'Clifton, NJ', 'clifton-nj-2', $market->id); // no page_index_states row
    PageIndexState::create(['site_id' => $site->id, 'content_id' => $indexed->id, 'url' => 'https://spg.example/clifton-nj/', 'url_normalized' => '/clifton-nj', 'index_verdict' => 'PASS']);

    $this->artisan('launchpad:report-duplicate-town-pages')
        ->assertSuccessful()
        ->expectsOutputToContain('index: indexed')
        ->expectsOutputToContain('index: not yet checked');
});

it('reports nothing when no town appears twice on a site', function () {
    $site = Site::factory()->create(['domain_url' => 'https://spg.example']);
    $market = Location::factory()->create(['site_id' => $site->id]);
    dupTownPage($site, 'Newark, NJ', 'newark-nj', $market->id);

    $this->artisan('launchpad:report-duplicate-town-pages')
        ->assertSuccessful()
        ->expectsOutputToContain('No same-town, same-site groups with more than one live page.');
});
