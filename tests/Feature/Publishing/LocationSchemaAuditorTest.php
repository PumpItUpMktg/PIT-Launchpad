<?php

use App\Enums\ContentKind;
use App\Enums\PageType;
use App\Models\Content;
use App\Models\Location;
use App\Models\Site;
use App\Publishing\Schema\LocationSchemaAuditor;

function auditSite(): Site
{
    return Site::factory()->create([
        'domain_url' => 'https://acme.example', 'brand_name' => 'Acme',
        'phone' => '(877) 786-7834', 'corporate_city' => 'Clifton', 'corporate_state' => 'NJ',
    ]);
}

function auditLocationPage(Site $site, array $locationOverrides): Content
{
    $location = Location::factory()->create(array_merge(['site_id' => $site->id], $locationOverrides));

    return Content::factory()->create([
        'site_id' => $site->id, 'kind' => ContentKind::Page, 'page_type' => PageType::Location,
        'location_id' => $location->id, 'title' => 'Bedminster, NJ', 'slug' => 'bedminster-nj',
    ]);
}

it('passes a well-formed storefront: own phone, own address, parentOrganization link', function () {
    $site = auditSite();
    auditLocationPage($site, [
        'is_storefront' => true, 'phone' => '(908) 224-0550',
        'address' => '10 Main St, Bedminster, NJ 07921',
        'address_components' => [
            ['long_name' => '10', 'types' => ['street_number']],
            ['long_name' => 'Main St', 'types' => ['route']],
            ['long_name' => 'Bedminster', 'types' => ['locality']],
            ['long_name' => 'New Jersey', 'short_name' => 'NJ', 'types' => ['administrative_area_level_1']],
        ],
    ]);

    $rows = app(LocationSchemaAuditor::class)->audit($site);

    expect($rows)->toHaveCount(1)
        ->and($rows[0]['ok'])->toBeTrue()
        ->and($rows[0]['telephone'])->toBe('(908) 224-0550')
        ->and($rows[0]['address'])->toContain('Main St');
});

it('flags a storefront with no address', function () {
    $site = auditSite();
    auditLocationPage($site, ['is_storefront' => true, 'phone' => '(908) 224-0550', 'address' => null, 'address_components' => null]);

    $rows = app(LocationSchemaAuditor::class)->audit($site);

    expect($rows[0]['ok'])->toBeFalse()
        ->and(implode(' ', $rows[0]['flags']))->toContain('storefront with NO address');
});

it('flags a telephone that collides with the corporate #org line', function () {
    $site = auditSite(); // corporate phone (877) 786-7834
    auditLocationPage($site, ['is_storefront' => true, 'phone' => '877-786-7834', 'address' => '1 A St, Clifton, NJ']);

    $rows = app(LocationSchemaAuditor::class)->audit($site);

    expect($rows[0]['ok'])->toBeFalse()
        ->and(implode(' ', $rows[0]['flags']))->toContain('collides with the corporate #org line');
});

it('flags a storefront with no telephone', function () {
    $site = auditSite();
    auditLocationPage($site, ['is_storefront' => true, 'phone' => null, 'address' => '1 A St, Clifton, NJ']);

    $rows = app(LocationSchemaAuditor::class)->audit($site);

    expect(implode(' ', $rows[0]['flags']))->toContain('no telephone');
});
