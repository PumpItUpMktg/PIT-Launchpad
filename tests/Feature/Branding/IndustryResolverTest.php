<?php

use App\Branding\IndustryResolver;
use App\Enums\ServiceSiloRole;
use App\Models\Service;
use App\Models\Site;

it('derives the industry from pillar services first', function () {
    $site = Site::factory()->create(['brand_name' => 'Acme']);
    Service::factory()->create(['site_id' => $site->id, 'name' => 'Drain Cleaning', 'silo_role' => ServiceSiloRole::Pillar]);
    Service::factory()->create(['site_id' => $site->id, 'name' => 'Water Heaters', 'silo_role' => ServiceSiloRole::Pillar]);
    Service::factory()->create(['site_id' => $site->id, 'name' => 'Coupons', 'silo_role' => ServiceSiloRole::Supporting]);

    expect((new IndustryResolver)->for($site))->toBe('Drain Cleaning, Water Heaters');
});

it('falls back to all services when none are pillars', function () {
    $site = Site::factory()->create(['brand_name' => 'Acme']);
    Service::factory()->create(['site_id' => $site->id, 'name' => 'AC Repair', 'silo_role' => ServiceSiloRole::Supporting]);

    expect((new IndustryResolver)->for($site))->toBe('AC Repair');
});

it('falls back to the brand name when there are no services', function () {
    $site = Site::factory()->create(['brand_name' => 'Acme Plumbing']);

    expect((new IndustryResolver)->for($site))->toBe('Acme Plumbing');
});
