<?php

use App\Models\Location;
use App\Models\Site;
use Illuminate\Support\Facades\Artisan;

test('site-nap reports the corporate NAP when captured', function () {
    $site = Site::factory()->create([
        'brand_name' => 'Sump Pump Gurus',
        'phone' => '877-786-7834',
        'corporate_street' => '10 Main St',
        'corporate_city' => 'Springfield',
        'corporate_state' => 'NJ',
        'corporate_postal_code' => '07081',
    ]);

    Artisan::call('launchpad:site-nap', ['site' => $site->id]);
    $out = Artisan::output();

    expect($out)->toContain('877-786-7834')
        ->toContain('10 Main St, Springfield, NJ 07081')
        ->toContain('[corporate]')
        ->toContain('Corporate NAP is captured');
});

test('site-nap flags a fall-back to a physical location when corporate is blank', function () {
    $site = Site::factory()->create(['brand_name' => 'Sump Pump Gurus']); // no corporate NAP
    Location::factory()->create([
        'site_id' => $site->id,
        'name' => 'Montclair',
        'phone' => '973-555-0100',
        'address' => '5 Bloomfield Ave, Montclair, NJ',
    ]);

    Artisan::call('launchpad:site-nap', ['site' => 'Sump Pump Gurus']);
    $out = Artisan::output();

    expect($out)->toContain('973-555-0100')            // the fallback phone it would render
        ->toContain('[fallback → Montclair]')
        ->toContain('falling back to the "Montclair" location')
        ->toContain('Setup → Business');               // the remedy
});
