<?php

use App\Enums\ServiceSiloRole;
use App\Models\Content;
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

it('composes a Service whose provider is the corporate #org Organization — no store NAP, no areaServed', function () {
    $site = Site::factory()->create([
        'domain_url' => 'https://acme.example',
        'brand_name' => 'Acme Plumbing',
        'legal_name' => 'Acme Plumbing LLC',
        'dba' => 'Acme',
        'phone' => '+15125550000',                    // corporate phone
        'corporate_street' => '500 Congress Ave',      // corporate address
        'corporate_city' => 'Austin',
        'corporate_state' => 'TX',
        'corporate_postal_code' => '78701',
    ]);
    SiteBranding::factory()->create([
        'site_id' => $site->id,
        'entity_type' => 'Plumber',
        // logo IMAGE url in ['url']; ['primary'] is a palette HEX and must NEVER reach the logo field.
        'logo_set' => ['url' => 'https://r2.cdn/acme/logo.png', 'primary' => '#0b75b5'],
        'same_as' => ['https://facebook.com/acme'],
    ]);

    $silo = Silo::factory()->create(['site_id' => $site->id, 'name' => 'Sump Pumps']);
    $pillar = Service::factory()->create(['site_id' => $site->id, 'name' => 'Sump Pump Installation', 'description' => 'Keep your basement dry.', 'silo_role' => ServiceSiloRole::Pillar]);
    $pillar->silos()->attach($silo->id);

    $node = buildServiceNode($site, serviceContent($site, $silo));

    expect($node['@type'])->toBe('Service')
        ->and($node['@id'])->toBe('https://acme.example/sump-pump-installation#service')
        ->and($node['name'])->toBe('Sump Pump Installation')
        ->and($node)->not->toHaveKey('areaServed');   // coverage lives ONLY on location pages

    $p = $node['provider'];
    expect($p['@type'])->toBe('Organization')          // corporate entity, NOT a LocalBusiness
        ->and($p['@id'])->toBe('https://acme.example/#org')
        ->and($p['name'])->toBe('Acme Plumbing')
        ->and($p['legalName'])->toBe('Acme Plumbing LLC')
        ->and($p['alternateName'])->toBe('Acme')
        ->and($p['url'])->toBe('https://acme.example')
        ->and($p['logo'])->toBe('https://r2.cdn/acme/logo.png')   // the URL, not the hex
        ->and($p['telephone'])->toBe('+15125550000')              // corporate phone (sites.phone)
        ->and($p['sameAs'])->toBe(['https://facebook.com/acme'])
        // corporate PostalAddress from the corporate_* fields — NOT a store address, NO geo/hours.
        ->and($p['address'])->toBe([
            '@type' => 'PostalAddress',
            'streetAddress' => '500 Congress Ave',
            'addressLocality' => 'Austin',
            'addressRegion' => 'TX',
            'postalCode' => '78701',
            'addressCountry' => 'US',
        ])
        ->and($p)->not->toHaveKey('geo')
        ->and($p)->not->toHaveKey('openingHoursSpecification');
});

it('the logo field is never a palette hex even when only a hex is present', function () {
    $site = Site::factory()->create(['domain_url' => 'https://acme.example', 'brand_name' => 'Acme']);
    SiteBranding::factory()->create(['site_id' => $site->id, 'logo_set' => ['primary' => '#0b75b5', 'accent' => '#123456'], 'same_as' => []]);

    $p = buildServiceNode($site, serviceContent($site))['provider'];

    expect($p)->not->toHaveKey('logo'); // no ['url'] → omitted, never the hex
});

it('degrades by omission for a bare tenant (Organization name/url only)', function () {
    $site = Site::factory()->create(['domain_url' => 'https://bare.example', 'brand_name' => 'Bare Co', 'legal_name' => null, 'dba' => null]);

    $node = buildServiceNode($site, serviceContent($site)); // no silo, no branding/locations/markets

    expect($node)->not->toHaveKey('serviceType')   // no silo service
        ->and($node)->not->toHaveKey('areaServed');

    $p = $node['provider'];
    expect($p['@type'])->toBe('Organization')
        ->and($p['name'])->toBe('Bare Co')
        ->and($p['url'])->toBe('https://bare.example')
        ->and($p)->not->toHaveKey('address')
        ->and($p)->not->toHaveKey('geo')
        ->and($p)->not->toHaveKey('telephone');
});

it('resolves serviceType to the pillar service, then stable order when no pillar', function () {
    $site = Site::factory()->create(['domain_url' => 'https://acme.example', 'brand_name' => 'Acme']);

    $silo = Silo::factory()->create(['site_id' => $site->id]);
    $supporting = Service::factory()->create(['site_id' => $site->id, 'name' => 'Aaa Supporting', 'silo_role' => ServiceSiloRole::Supporting]);
    $pillar = Service::factory()->create(['site_id' => $site->id, 'name' => 'Zzz Pillar', 'silo_role' => ServiceSiloRole::Pillar]);
    $supporting->silos()->attach($silo->id);
    $pillar->silos()->attach($silo->id);

    expect(buildServiceNode($site, serviceContent($site, $silo, 'svc-pillar'))['serviceType'])->toBe('Zzz Pillar');

    $silo2 = Silo::factory()->create(['site_id' => $site->id]);
    $s1 = Service::factory()->create(['site_id' => $site->id, 'name' => 'Alpha Repair', 'silo_role' => ServiceSiloRole::Supporting]);
    $s2 = Service::factory()->create(['site_id' => $site->id, 'name' => 'Beta Repair', 'silo_role' => ServiceSiloRole::Supporting]);
    $s1->silos()->attach($silo2->id);
    $s2->silos()->attach($silo2->id);

    expect(buildServiceNode($site, serviceContent($site, $silo2, 'svc-nopillar'))['serviceType'])->toBe('Alpha Repair');
});
