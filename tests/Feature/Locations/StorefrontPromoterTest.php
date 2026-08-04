<?php

use App\Enums\ContentKind;
use App\Enums\PageType;
use App\Locations\StorefrontPromoter;
use App\Models\Content;
use App\Models\Location;
use App\Models\Site;

function sfSite(): Site
{
    return Site::factory()->create(['brand_name' => 'SPG', 'domain_url' => 'https://spg.example']);
}

function sfLocationPage(Site $site, array $loc): Location
{
    $location = Location::factory()->create(array_merge(['site_id' => $site->id], $loc));
    Content::factory()->create([
        'site_id' => $site->id, 'kind' => ContentKind::Page, 'page_type' => PageType::Location,
        'location_id' => $location->id, 'title' => (string) ($loc['name'] ?? 'Loc'), 'slug' => 'loc-'.uniqid(),
    ]);

    return $location;
}

it('promotes a service-area location that has an address, and reports one that does not', function () {
    $site = sfSite();
    $withAddr = sfLocationPage($site, ['name' => 'Bedminster', 'is_storefront' => false, 'address' => '10 Main St, Bedminster, NJ 07921']);
    $noAddr = sfLocationPage($site, ['name' => 'Hoboken', 'is_storefront' => false, 'address' => null, 'address_components' => null]);
    $already = sfLocationPage($site, ['name' => 'Hackettstown', 'is_storefront' => true, 'address' => '78 Main St, Hackettstown, NJ']);

    $plan = app(StorefrontPromoter::class)->plan($site, apply: true);

    expect(collect($plan['promote'])->pluck('name')->all())->toBe(['Bedminster'])
        ->and(collect($plan['missing'])->pluck('name')->all())->toBe(['Hoboken'])
        ->and($plan['already'])->toBe(1);

    expect($withAddr->fresh()->is_storefront)->toBeTrue()   // flipped
        ->and($noAddr->fresh()->is_storefront)->toBeFalse() // left alone — no address
        ->and($already->fresh()->is_storefront)->toBeTrue();
});

it('resolves an address from GBP address_components when the flat string is empty', function () {
    $site = sfSite();
    $loc = sfLocationPage($site, [
        'name' => 'Doylestown', 'is_storefront' => false, 'address' => null,
        'address_components' => [
            ['long_name' => '20', 'types' => ['street_number']],
            ['long_name' => 'State St', 'types' => ['route']],
            ['long_name' => 'Doylestown', 'types' => ['locality']],
        ],
    ]);

    $plan = app(StorefrontPromoter::class)->plan($site, apply: true);

    expect($plan['promote'])->toHaveCount(1)
        ->and($plan['promote'][0]['address'])->toBe('20 State St, Doylestown')
        ->and($loc->fresh()->is_storefront)->toBeTrue();
});

it('preview does not flip anything', function () {
    $site = sfSite();
    $loc = sfLocationPage($site, ['name' => 'Bedminster', 'is_storefront' => false, 'address' => '10 Main St, Bedminster, NJ']);

    $plan = app(StorefrontPromoter::class)->plan($site, apply: false);

    expect($plan['promote'])->toHaveCount(1)
        ->and($loc->fresh()->is_storefront)->toBeFalse();
});

it('only touches Locations that back a location page', function () {
    $site = sfSite();
    // A Location with an address but NO location page — not in scope.
    Location::factory()->create(['site_id' => $site->id, 'is_storefront' => false, 'address' => '1 Nowhere Rd']);

    $plan = app(StorefrontPromoter::class)->plan($site, apply: true);

    expect($plan['promote'])->toBe([])->and($plan['missing'])->toBe([]);
});
