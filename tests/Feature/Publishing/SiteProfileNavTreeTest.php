<?php

use App\Audit\Support\SurfaceSets;
use App\Enums\ContentKind;
use App\Enums\ContentStatus;
use App\Enums\PageType;
use App\Models\Content;
use App\Models\Scopes\SiteScope;
use App\Models\Site;
use App\Publishing\Chrome\SiteProfileAssembler;

/** A published service/hub page. */
function navPage(Site $site, string $slug, string $title, array $extra = []): Content
{
    return Content::withoutGlobalScope(SiteScope::class)->create(array_merge([
        'site_id' => $site->id, 'kind' => ContentKind::Page, 'page_type' => PageType::Service,
        'title' => $title, 'slug' => $slug, 'version' => 1, 'status' => ContentStatus::Published,
    ], $extra));
}

function assembledServices(Site $site): array
{
    return app(SiteProfileAssembler::class)->assemble($site->fresh())['services'];
}

it('carries the full title as label and the short nav_label alongside it', function () {
    $site = Site::factory()->create(['domain_url' => 'https://apex.example']);
    navPage($site, 'sump-pump-installation', 'Sump Pump Installation', ['nav_label' => 'Installation']);

    $services = assembledServices($site);

    expect($services)->toHaveCount(1)
        ->and($services[0]['label'])->toBe('Sump Pump Installation')   // full title stays the label
        ->and($services[0]['nav_label'])->toBe('Installation');        // short label rides alongside
});

it('omits nav_label when the page has none', function () {
    $site = Site::factory()->create(['domain_url' => 'https://apex.example']);
    navPage($site, 'radon-mitigation', 'Radon Mitigation');

    expect(assembledServices($site)[0])->not->toHaveKey('nav_label');
});

it('nests spokes under a CURATED hub (curation controls columns, the silo tree fills them)', function () {
    $site = Site::factory()->create(['domain_url' => 'https://apex.example']);
    // Operator curates the hub into the services bar; its spokes are not themselves featured.
    $hub = navPage($site, 'sump-pumps', 'Sump Pumps', ['page_type' => PageType::Hub, 'nav_featured' => true]);
    navPage($site, 'sump-pump-installation', 'Sump Pump Installation', ['parent_content_id' => $hub->id, 'nav_label' => 'Installation']);
    navPage($site, 'sump-pump-repair', 'Sump Pump Repair', ['parent_content_id' => $hub->id, 'nav_label' => 'Repair']);

    $services = assembledServices($site);

    expect($services)->toHaveCount(1)                                  // hub is the single top-level item
        ->and($services[0]['label'])->toBe('Sump Pumps')
        ->and($services[0]['children'])->toHaveCount(2);              // spokes nested (curated no longer flattens)

    $childLabels = array_column($services[0]['children'], 'label');
    $childNav = array_column($services[0]['children'], 'nav_label');
    expect($childLabels)->toContain('Sump Pump Installation')->toContain('Sump Pump Repair')
        ->and($childNav)->toContain('Installation')->toContain('Repair');
});

it('emits the grouped-nav menu-mode thresholds from config', function () {
    config()->set('launchpad.nav.flat_max', 5);
    config()->set('launchpad.nav.group_overflow', 7);
    $site = Site::factory()->create(['domain_url' => 'https://apex.example']);

    $navMenu = app(SiteProfileAssembler::class)->assemble($site->fresh())['nav_menu'];

    expect($navMenu)->toBe(['flat_max' => 5, 'group_overflow' => 7]);
});

it('keeps the GRID-001 audit title-based — a short nav_label never changes the compared set', function () {
    $site = Site::factory()->create(['domain_url' => 'https://apex.example', 'brand_name' => 'SPG']);
    navPage($site, 'sump-pump-installation', 'Sump Pump Installation', ['nav_label' => 'Installation', 'wp_post_id' => 10]);

    // The audit surface still reads the full titles, not the shortened header labels.
    expect(app(SurfaceSets::class)->navServiceLabels($site->fresh()))->toBe(['Sump Pump Installation']);
});
