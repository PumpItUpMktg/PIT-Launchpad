<?php

use App\Enums\ContentKind;
use App\Enums\PageType;
use App\Models\Content;
use App\Models\Location;
use App\Models\Site;

function msLocPage(Site $site, array $loc): void
{
    $location = Location::factory()->create(array_merge(['site_id' => $site->id], $loc));
    Content::factory()->create([
        'site_id' => $site->id, 'kind' => ContentKind::Page, 'page_type' => PageType::Location,
        'location_id' => $location->id, 'title' => (string) ($loc['name'] ?? 'Loc'), 'slug' => 'loc-'.uniqid(),
    ]);
}

it('previews then applies the storefront promotion', function () {
    $site = Site::factory()->create(['brand_name' => 'SPG', 'domain_url' => 'https://spg.example']);
    msLocPage($site, ['name' => 'Bedminster', 'is_storefront' => false, 'address' => '10 Main St, Bedminster, NJ']);

    $this->artisan('launchpad:mark-storefronts', ['site' => 'SPG'])
        ->expectsOutputToContain('would promote')
        ->assertSuccessful();
    expect(Location::where('site_id', $site->id)->value('is_storefront'))->toBeFalse();

    $this->artisan('launchpad:mark-storefronts', ['site' => 'SPG', '--apply' => true])->assertSuccessful();
    expect(Location::where('site_id', $site->id)->value('is_storefront'))->toBeTrue();
});

it('fails on an unknown site', function () {
    $this->artisan('launchpad:mark-storefronts', ['site' => 'nope'])->assertFailed();
});
