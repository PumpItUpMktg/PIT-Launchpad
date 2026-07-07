<?php

use App\Models\Location;
use App\Models\Site;
use App\Publishing\SiteContact;

it('prefers the primary location phone, else the site business phone', function () {
    $contact = new SiteContact;

    // Site business phone only (guided flow captured it; no Location phone yet).
    $site = Site::factory()->create(['phone' => '(973) 555-0100']);
    expect($contact->phone($site))->toBe('(973) 555-0100');

    // A location phone wins over the site business phone (multi-location NAP override).
    Location::factory()->create(['site_id' => $site->id, 'phone' => '(201) 555-0199']);
    expect($contact->phone($site->fresh()))->toBe('(201) 555-0199');
});

it('returns null only when a site has no number anywhere', function () {
    $site = Site::factory()->create(['phone' => null]);
    expect(new SiteContact()->phone($site))->toBeNull();
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
