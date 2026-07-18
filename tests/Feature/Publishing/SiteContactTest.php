<?php

use App\Models\Location;
use App\Models\Site;
use App\Publishing\SiteContact;

it('prefers the corporate business phone over any location, so the site-wide number is the main line', function () {
    $contact = new SiteContact;

    // Corporate business phone from intake wins — even with a differing location phone (multi-location).
    $site = Site::factory()->create(['phone' => '(973) 555-0100']);
    Location::factory()->create(['site_id' => $site->id, 'phone' => '(201) 555-0199']);
    expect($contact->phone($site->fresh()))->toBe('(973) 555-0100');
});

it('falls back to the primary location phone only when no corporate phone was captured (legacy)', function () {
    $site = Site::factory()->create(['phone' => null]);
    Location::factory()->create(['site_id' => $site->id, 'phone' => '(201) 555-0199']);
    expect(new SiteContact()->phone($site->fresh()))->toBe('(201) 555-0199');
});

it('returns null only when a site has no number anywhere', function () {
    $site = Site::factory()->create(['phone' => null]);
    expect(new SiteContact()->phone($site))->toBeNull();
});

it('prefers the corporate address over any location, formatted as one line', function () {
    $contact = new SiteContact;

    $site = Site::factory()->create([
        'corporate_street' => '10 Main St',
        'corporate_city' => 'Springfield',
        'corporate_state' => 'NJ',
        'corporate_postal_code' => '07081',
    ]);
    Location::factory()->create(['site_id' => $site->id, 'address' => '99 Depot Rd, Newark, NJ']);

    expect($contact->address($site->fresh()))->toBe('10 Main St, Springfield, NJ 07081');
});

it('falls back to the primary location address when no corporate address was captured (legacy)', function () {
    $site = Site::factory()->create(['corporate_street' => null]);
    Location::factory()->create(['site_id' => $site->id, 'address' => '99 Depot Rd, Newark, NJ']);

    expect(new SiteContact()->address($site->fresh()))->toBe('99 Depot Rd, Newark, NJ')
        ->and(new SiteContact()->address(Site::factory()->create()))->toBeNull();
});

it('resolves the emergency line to the dedicated number, else the display phone', function () {
    $contact = new SiteContact;

    $site = Site::factory()->create(['phone' => '(973) 555-0100', 'emergency_phone' => '(973) 555-9111']);
    expect($contact->emergencyPhone($site))->toBe('(973) 555-9111');

    $noEmergency = Site::factory()->create(['phone' => '(973) 555-0100', 'emergency_phone' => null]);
    expect($contact->emergencyPhone($noEmergency))->toBe('(973) 555-0100');
});

it('builds a tel: href from any number, null when blank', function () {
    $contact = new SiteContact;
    expect($contact->tel('(973) 555-0100'))->toBe('tel:9735550100')
        ->and($contact->tel(null))->toBeNull()
        ->and($contact->tel('  '))->toBeNull();
});
