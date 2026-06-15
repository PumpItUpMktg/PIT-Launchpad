<?php

use App\Enums\ServiceSiloRole;
use App\Models\Content;
use App\Models\Location;
use App\Models\Market;
use App\Models\Service;
use App\Models\Silo;
use App\Models\Site;
use App\Models\SiteBranding;
use App\Publishing\Schema\ServiceSchemaBuilder;

function buildServiceNode(Site $site, Content $content): array
{
    return app(ServiceSchemaBuilder::class)->build(
        $content,
        $site,
        'https://acme.example/',
        'https://acme.example/sump-pump-installation',
    );
}

function serviceContent(Site $site, ?Silo $silo = null, string $slug = 'sump-pump-installation'): Content
{
    return Content::factory()->page()->create([
        'site_id' => $site->id,
        'silo_id' => $silo?->id,
        'slug' => $slug,
    ]);
}

it('composes the full Service node with an inline @id-bearing LocalBusiness provider', function () {
    $site = Site::factory()->create([
        'domain_url' => 'https://acme.example',
        'brand_name' => 'Acme Plumbing',
        'legal_name' => 'Acme Plumbing LLC',
        'dba' => 'Acme',
    ]);
    SiteBranding::factory()->create([
        'site_id' => $site->id,
        'entity_type' => 'Plumber',
        'logo_set' => ['primary' => 'https://r2.cdn/acme/logo.png'],
        'same_as' => ['https://facebook.com/acme'],
    ]);
    Location::factory()->create([
        'site_id' => $site->id,
        'is_storefront' => true,
        'phone' => '+15125551234',
        'address' => '123 Main St, Austin, TX 78701',
        'address_components' => [
            ['long_name' => '123', 'short_name' => '123', 'types' => ['street_number']],
            ['long_name' => 'Main St', 'short_name' => 'Main St', 'types' => ['route']],
            ['long_name' => 'Austin', 'short_name' => 'Austin', 'types' => ['locality']],
            ['long_name' => 'Texas', 'short_name' => 'TX', 'types' => ['administrative_area_level_1']],
            ['long_name' => '78701', 'short_name' => '78701', 'types' => ['postal_code']],
        ],
        'lat' => 30.2672,
        'lng' => -97.7431,
        'hours' => ['mon' => ['open' => '08:00', 'close' => '17:00'], 'sun' => 'closed'],
    ]);
    Market::factory()->create(['site_id' => $site->id, 'name' => 'Austin', 'region' => 'TX', 'is_covered' => true]);
    Market::factory()->create(['site_id' => $site->id, 'name' => 'Dallas', 'region' => 'TX', 'is_covered' => false]);

    $silo = Silo::factory()->create(['site_id' => $site->id, 'name' => 'Sump Pumps']);
    $pillar = Service::factory()->create(['site_id' => $site->id, 'name' => 'Sump Pump Installation', 'description' => 'Keep your basement dry.', 'silo_role' => ServiceSiloRole::Pillar]);
    $pillar->silos()->attach($silo->id);

    $node = buildServiceNode($site, serviceContent($site, $silo));

    expect($node['@type'])->toBe('Service')
        ->and($node['@id'])->toBe('https://acme.example/sump-pump-installation#service')
        ->and($node['name'])->toBe('Sump Pump Installation')
        ->and($node['serviceType'])->toBe('Sump Pump Installation')
        ->and($node['description'])->toBe('Keep your basement dry.');

    $p = $node['provider'];
    expect($p['@type'])->toBe('Plumber')
        ->and($p['@id'])->toBe('https://acme.example/#business')
        ->and($p['name'])->toBe('Acme Plumbing')
        ->and($p['legalName'])->toBe('Acme Plumbing LLC')
        ->and($p['alternateName'])->toBe('Acme')
        ->and($p['url'])->toBe('https://acme.example')
        ->and($p['logo'])->toBe('https://r2.cdn/acme/logo.png')
        ->and($p['telephone'])->toBe('+15125551234')
        ->and($p['sameAs'])->toBe(['https://facebook.com/acme']);

    expect($p['address'])->toBe([
        '@type' => 'PostalAddress',
        'streetAddress' => '123 Main St',
        'addressLocality' => 'Austin',
        'addressRegion' => 'TX',
        'postalCode' => '78701',
    ]);
    expect($p['geo'])->toBe(['@type' => 'GeoCoordinates', 'latitude' => 30.2672, 'longitude' => -97.7431])
        ->and($p['openingHoursSpecification'])->toBe([
            ['@type' => 'OpeningHoursSpecification', 'dayOfWeek' => 'Monday', 'opens' => '08:00', 'closes' => '17:00'],
        ]);

    // areaServed = covered markets only, named City (+ containedInPlace region).
    expect($node['areaServed'])->toBe([
        ['@type' => 'City', 'name' => 'Austin', 'containedInPlace' => ['@type' => 'AdministrativeArea', 'name' => 'TX']],
    ]);
});

it('degrades by omission for a bare tenant (name/url provider only, no fabricated fields)', function () {
    $site = Site::factory()->create(['domain_url' => 'https://bare.example', 'brand_name' => 'Bare Co', 'legal_name' => null, 'dba' => null]);

    $node = buildServiceNode($site, serviceContent($site)); // no silo, no branding/locations/markets

    expect($node)->toHaveKey('@type')
        ->and($node)->not->toHaveKey('serviceType')   // no silo service
        ->and($node)->not->toHaveKey('areaServed');   // no covered markets

    $p = $node['provider'];
    expect($p['@type'])->toBe('LocalBusiness')         // entity_type fallback
        ->and($p['name'])->toBe('Bare Co')
        ->and($p['url'])->toBe('https://bare.example')
        ->and($p)->not->toHaveKey('address')           // no complete location, no canonical_nap
        ->and($p)->not->toHaveKey('geo')               // never fabricated
        ->and($p)->not->toHaveKey('telephone')
        ->and($p)->not->toHaveKey('openingHoursSpecification');
});

it('falls back to canonical_nap (address + phone, NEVER geo) when no location is complete', function () {
    $site = Site::factory()->create(['domain_url' => 'https://acme.example', 'brand_name' => 'Acme']);
    SiteBranding::factory()->create([
        'site_id' => $site->id,
        'entity_type' => null,
        'canonical_nap' => ['name' => 'Acme', 'address' => '500 Congress Ave, Austin, TX', 'phone' => '+15120000000'],
        'same_as' => [],
    ]);
    // A location with NO geo → not "complete" → cascade falls through.
    Location::factory()->create(['site_id' => $site->id, 'lat' => null, 'lng' => null, 'address' => '1 No Geo Rd', 'address_components' => null]);

    $p = buildServiceNode($site, serviceContent($site))['provider'];

    expect($p['telephone'])->toBe('+15120000000')
        ->and($p['address'])->toBe('500 Congress Ave, Austin, TX') // flat Text address
        ->and($p)->not->toHaveKey('geo');                          // no GeoCoordinates fabricated
});

it('resolves serviceType to the pillar service, then stable order when no pillar', function () {
    $site = Site::factory()->create(['domain_url' => 'https://acme.example', 'brand_name' => 'Acme']);

    // Pillar wins over a supporting service in the same silo.
    $silo = Silo::factory()->create(['site_id' => $site->id]);
    $supporting = Service::factory()->create(['site_id' => $site->id, 'name' => 'Aaa Supporting', 'silo_role' => ServiceSiloRole::Supporting]);
    $pillar = Service::factory()->create(['site_id' => $site->id, 'name' => 'Zzz Pillar', 'silo_role' => ServiceSiloRole::Pillar]);
    $supporting->silos()->attach($silo->id);
    $pillar->silos()->attach($silo->id);

    expect(buildServiceNode($site, serviceContent($site, $silo, 'svc-pillar'))['serviceType'])->toBe('Zzz Pillar'); // pillar despite name order

    // No pillar → stable name order picks the first.
    $silo2 = Silo::factory()->create(['site_id' => $site->id]);
    $s1 = Service::factory()->create(['site_id' => $site->id, 'name' => 'Alpha Repair', 'silo_role' => ServiceSiloRole::Supporting]);
    $s2 = Service::factory()->create(['site_id' => $site->id, 'name' => 'Beta Repair', 'silo_role' => ServiceSiloRole::Supporting]);
    $s1->silos()->attach($silo2->id);
    $s2->silos()->attach($silo2->id);

    expect(buildServiceNode($site, serviceContent($site, $silo2, 'svc-nopillar'))['serviceType'])->toBe('Alpha Repair');
});
