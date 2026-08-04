<?php

use App\Enums\ContentKind;
use App\Enums\PageType;
use App\Models\Content;
use App\Models\Location;
use App\Models\Site;

it('reports a storefront address gap for a tenant', function () {
    $site = Site::factory()->create(['brand_name' => 'SPG', 'domain_url' => 'https://spg.example', 'phone' => '(877) 786-7834']);
    $loc = Location::factory()->create(['site_id' => $site->id, 'is_storefront' => true, 'phone' => '(908) 224-0550', 'address' => null, 'address_components' => null]);
    Content::factory()->create([
        'site_id' => $site->id, 'kind' => ContentKind::Page, 'page_type' => PageType::Location,
        'location_id' => $loc->id, 'title' => 'Bedminster, NJ', 'slug' => 'bedminster-nj',
    ]);

    $this->artisan('launchpad:audit-location-schema', ['site' => 'SPG'])
        ->expectsOutputToContain('storefront with NO address')
        ->assertSuccessful();
});

it('fails on an unknown site', function () {
    $this->artisan('launchpad:audit-location-schema', ['site' => 'nope'])->assertFailed();
});
