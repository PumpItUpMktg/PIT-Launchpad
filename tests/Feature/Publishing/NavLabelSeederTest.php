<?php

use App\Enums\ContentKind;
use App\Models\Content;
use App\Models\Site;
use App\Publishing\Chrome\NavLabelSeeder;

/** A hub landing page. */
function navHub(Site $site, string $title): Content
{
    return Content::factory()->create(['site_id' => $site->id, 'kind' => ContentKind::Page->value, 'title' => $title]);
}

/** A child page nested under $hub. */
function navChild(Site $site, Content $hub, string $title, array $extra = []): Content
{
    return Content::factory()->create(array_merge([
        'site_id' => $site->id, 'kind' => ContentKind::Page->value, 'title' => $title, 'parent_content_id' => $hub->id,
    ], $extra));
}

it('seeds derived nav labels for a hub\'s children and clears where it should fall back', function () {
    $site = Site::factory()->create(['brand_name' => 'Sump Pump Gurus']);
    $hub = navHub($site, 'Sump Pumps');
    $install = navChild($site, $hub, 'Sump Pump Installation');
    $repair = navChild($site, $hub, 'Sump Pump Repair');
    $radon = navChild($site, $hub, 'Radon Mitigation');   // unrelated → falls back to title

    $changed = app(NavLabelSeeder::class)->seed($site);

    expect($changed)->toBe(2)                              // install + repair got labels; radon stays null
        ->and($install->refresh()->nav_label)->toBe('Installation')
        ->and($repair->refresh()->nav_label)->toBe('Repair')
        ->and($radon->refresh()->nav_label)->toBeNull();
});

it('never overwrites an operator-confirmed label', function () {
    $site = Site::factory()->create(['brand_name' => 'Sump Pump Gurus']);
    $hub = navHub($site, 'Sump Pumps');
    $child = navChild($site, $hub, 'Sump Pump Installation', ['nav_label' => 'Install', 'nav_label_confirmed' => true]);

    app(NavLabelSeeder::class)->seed($site);

    expect($child->refresh()->nav_label)->toBe('Install');   // operator value preserved, not re-derived to "Installation"
});

it('leaves a top-level page (no hub parent) untouched', function () {
    $site = Site::factory()->create();
    Content::factory()->create(['site_id' => $site->id, 'kind' => ContentKind::Page->value, 'title' => 'About Us', 'parent_content_id' => null]);

    expect(app(NavLabelSeeder::class)->seed($site))->toBe(0);
});

it('is idempotent — a second run changes nothing', function () {
    $site = Site::factory()->create(['brand_name' => 'Acme']);
    $hub = navHub($site, 'Backup Systems');
    navChild($site, $hub, 'Battery Backup');
    navChild($site, $hub, 'Water-Powered Backup');

    app(NavLabelSeeder::class)->seed($site);
    expect(app(NavLabelSeeder::class)->seed($site))->toBe(0);
});

it('runs from the launchpad:seed-nav-labels command', function () {
    $site = Site::factory()->create(['brand_name' => 'Sump Pump Gurus']);
    $hub = navHub($site, 'Sump Pumps');
    $install = navChild($site, $hub, 'Sump Pump Installation');

    $this->artisan('launchpad:seed-nav-labels', ['--site' => $site->id])->assertExitCode(0);

    expect($install->refresh()->nav_label)->toBe('Installation');
});
