<?php

use App\Enums\ContentKind;
use App\Enums\PageType;
use App\Models\Content;
use App\Models\Location;
use App\Models\Site;
use App\Models\SiteBranding;
use App\Publishing\Schema\LocationSchemaBuilder;

it('builds a per-location LocalBusiness with parentOrganization → #org and NAP === the Location record', function () {
    $site = Site::factory()->create([
        'domain_url' => 'https://acme.example', 'brand_name' => 'Acme Plumbing',
        'phone' => '+15125550000', 'corporate_street' => '500 Congress Ave', 'corporate_city' => 'Austin',
        'corporate_state' => 'TX', 'corporate_postal_code' => '78701',
    ]);
    SiteBranding::factory()->create([
        'site_id' => $site->id, 'entity_type' => 'Plumber',
        'logo_set' => ['url' => 'https://r2.cdn/acme/logo.png', 'primary' => '#0b75b5'], 'same_as' => [],
    ]);

    $location = Location::factory()->create([
        'site_id' => $site->id, 'is_storefront' => true, 'phone' => '(908) 520-6660',
        'address' => '10 Store St, Hackettstown, NJ 07840',
        'address_components' => [
            ['long_name' => '10', 'short_name' => '10', 'types' => ['street_number']],
            ['long_name' => 'Store St', 'short_name' => 'Store St', 'types' => ['route']],
            ['long_name' => 'Hackettstown', 'short_name' => 'Hackettstown', 'types' => ['locality']],
            ['long_name' => 'New Jersey', 'short_name' => 'NJ', 'types' => ['administrative_area_level_1']],
            ['long_name' => '07840', 'short_name' => '07840', 'types' => ['postal_code']],
        ],
        'lat' => 40.8537, 'lng' => -74.8290, 'gbp_url' => 'https://maps.google.com/?cid=9',
        'served_towns' => [['name' => 'Washington', 'state' => 'NJ'], ['name' => 'Mansfield', 'state' => 'NJ']],
    ]);

    $content = Content::factory()->create([
        'site_id' => $site->id, 'kind' => ContentKind::Page, 'page_type' => PageType::Location,
        'location_id' => $location->id, 'slug' => 'hackettstown-nj',
    ]);

    $node = app(LocationSchemaBuilder::class)->buildForLocation(
        $content, $location, $site, 'https://acme.example/', 'https://acme.example/hackettstown-nj'
    );

    // A per-location @id — NOT the corporate entity.
    expect($node['@type'])->toBe('Plumber')
        ->and($node['@id'])->toBe('https://acme.example/#location-hackettstown-nj')
        // GBP consistency: the rendered NAP is exactly the Location record's.
        ->and($node['telephone'])->toBe('(908) 520-6660')
        ->and($node['address'])->toBe([
            '@type' => 'PostalAddress', 'streetAddress' => '10 Store St', 'addressLocality' => 'Hackettstown',
            'addressRegion' => 'NJ', 'postalCode' => '07840',
        ])
        ->and($node['geo'])->toBe(['@type' => 'GeoCoordinates', 'latitude' => 40.8537, 'longitude' => -74.829])
        ->and($node['hasMap'])->toBe('https://maps.google.com/?cid=9');

    // areaServed = this location's city + served towns (clean names only).
    expect(collect($node['areaServed'])->pluck('name')->all())->toBe(['Hackettstown', 'Washington', 'Mansfield']);

    // parentOrganization is the sitewide corporate #org (inline so the @id resolves in-graph).
    expect($node['parentOrganization']['@type'])->toBe('Organization')
        ->and($node['parentOrganization']['@id'])->toBe('https://acme.example/#org')
        ->and($node['parentOrganization']['logo'])->toBe('https://r2.cdn/acme/logo.png')
        ->and($node['parentOrganization']['telephone'])->toBe('+15125550000'); // corporate, not the store line
});
