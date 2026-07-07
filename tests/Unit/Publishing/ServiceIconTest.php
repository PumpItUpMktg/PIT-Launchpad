<?php

use App\Publishing\Blocks\BlockBuilder;
use App\Publishing\Blocks\BlockSections;
use App\Publishing\Blocks\ServiceIcon;

it('resolves service names across many trades to sensible icon slugs', function (string $title, string $slug) {
    expect((new ServiceIcon)->slugFor($title))->toBe($slug);
})->with([
    // Plumbing / sewer
    ['Drain Cleaning', 'drain'],
    ['Sewer Line Repair', 'pipe'],
    ['Camera Inspection', 'camera'],
    ['Leak Detection', 'droplet'],
    ['Sump Pump Installation', 'pump'],
    ['Hydro Jetting', 'jet'],
    ['Grease Trap Service', 'grease'],
    ['Water Heater Replacement', 'heater'],
    ['Tankless Water Heaters', 'heater'],
    ['Toilet Repair', 'toilet'],
    ['Faucet & Fixture Install', 'faucet'],
    ['Gas Line Repair', 'gas'],
    // HVAC (heat pump must NOT become a plumbing pump)
    ['Air Conditioning Repair', 'hvac'],
    ['Furnace Installation', 'hvac'],
    ['Heat Pump Service', 'hvac'],
    ['Duct Cleaning', 'hvac'],
    // Electrical / solar (solar panel must NOT become electrical)
    ['Electrical Panel Upgrade', 'electric'],
    ['Recessed Lighting', 'electric'],
    ['Solar Panel Installation', 'solar'],
    // Exterior trades
    ['Roof Replacement', 'roof'],
    ['Gutter Cleaning', 'roof'],
    ['Lawn Care', 'lawn'],
    ['Tree Removal', 'tree'],
    ['Stump Grinding', 'tree'],
    ['Interior Painting', 'paint'],
    ['Deck Staining', 'paint'],
    ['Hardwood Flooring', 'floor'],
    ['Fence Installation', 'fence'],
    ['Concrete Driveway', 'concrete'],
    // Cleaning / pest / appliance
    ['House Cleaning', 'clean'],
    ['Pressure Washing', 'clean'],
    ['Carpet Cleaning', 'clean'],
    ['Pest Control', 'pest'],
    ['Termite Treatment', 'pest'],
    ['Refrigerator Repair', 'appliance'],
    ['Washer & Dryer Repair', 'appliance'],
    // Doors / security / auto / handyman
    ['Garage Door Repair', 'garage'],
    ['Window Replacement', 'window'],
    ['Locksmith Services', 'lock'],
    ['Home Security Systems', 'shield'],
    ['Brake Service', 'auto'],
    ['Home Remodeling', 'tools'],
    // Emergency + the substring guard ("maintenance" contains "main")
    ['24/7 Emergency Service', 'bolt'],
    ['Preventive Maintenance', 'wrench'],
    // Unmatched → the fallback, never empty
    ['Notary Public', ServiceIcon::FALLBACK],
    ['', ServiceIcon::FALLBACK],
]);

it('every emittable icon slug is styled by the theme — a card icon is NEVER empty for any tenant', function () {
    $css = file_get_contents(dirname(__DIR__, 3).'/wordpress-theme/launchpad-blocks/assets/theme.css');

    // The fallback is drawn by the base `.lp-icon::before`; every other slug needs its own rule.
    expect($css)->toContain('.lp-icon::before');

    foreach (ServiceIcon::slugs() as $slug) {
        if ($slug === ServiceIcon::FALLBACK) {
            continue;
        }
        expect(str_contains($css, ".lp-icon--{$slug}::before"))
            ->toBeTrue("theme.css is missing an icon rule for slug '{$slug}' — a service mapped to it would render empty");
    }
});

it('emits the icon as a kses-safe class, never inline SVG that WP would strip', function () {
    $sections = new BlockSections(new BlockBuilder);
    $markup = $sections->servicesGrid('What we do', 'Our services', [
        ['title' => 'Drain Cleaning', 'blurb' => 'x', 'url' => 'https://x.test/drain'],
    ]);

    expect($markup)->toContain('lp-icon lp-icon--drain')
        ->and($markup)->not->toContain('<svg'); // no inline SVG in post_content
});
