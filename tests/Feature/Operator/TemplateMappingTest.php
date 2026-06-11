<?php

use App\Enums\ConnectionProvider;
use App\Models\Connection;
use App\Models\Scopes\SiteScope;
use App\Models\Site;
use App\Models\SiteTemplateMapping;
use App\Operator\Controls\TemplateMapping;
use Illuminate\Support\Facades\Http;

function mappingSite(): Site
{
    $site = Site::factory()->create(['domain_url' => 'https://apex.example']);
    Connection::factory()->rotated()->create([
        'site_id' => $site->id,
        'provider' => ConnectionProvider::WpAppPassword->value,
        'credentials' => ['base_url' => 'https://apex.example', 'username' => 'u', 'app_password' => 'p'],
    ]);

    return $site;
}

it('fetches the live template inventory for a site', function () {
    Http::fake(['*/wp-json/launchpad/v1/templates' => Http::response([
        'templates' => [
            ['id' => 11, 'title' => 'Service Page', 'type' => 'page'],
            ['id' => 12, 'title' => 'Blog Single', 'type' => 'single-post'],
        ],
    ])]);

    $inventory = app(TemplateMapping::class)->inventory(mappingSite());

    expect($inventory)->toHaveCount(2)
        ->and($inventory[0]['id'])->toBe(11);
});

it('maps a kit to a template and resolves it', function () {
    $site = mappingSite();
    $mapping = app(TemplateMapping::class)->map($site, 'service-page', 11, 'Service Page');

    expect($mapping->version)->toBe(1)
        ->and($mapping->template_id)->toBe(11);

    $resolved = app(TemplateMapping::class)->resolve($site, 'service-page');
    expect($resolved->template_id)->toBe(11)
        ->and($resolved->template_title)->toBe('Service Page');
});

it('bumps the version only when the target template actually changes', function () {
    $site = mappingSite();
    $service = app(TemplateMapping::class);

    $service->map($site, 'service-page', 11, 'Service Page');
    $noop = $service->map($site, 'service-page', 11, 'Service Page (renamed)');
    expect($noop->version)->toBe(1); // same target → no bump

    $remapped = $service->map($site, 'service-page', 22, 'Other Template');
    expect($remapped->version)->toBe(2)   // changed target → bump
        ->and($remapped->template_id)->toBe(22);

    // Still one current row per (site, kit).
    expect(SiteTemplateMapping::withoutGlobalScope(SiteScope::class)
        ->where('site_id', $site->id)->where('kit', 'service-page')->count())->toBe(1);
});

it('current() keys the mappings by kit', function () {
    $site = mappingSite();
    $service = app(TemplateMapping::class);
    $service->map($site, 'service-page', 11);
    $service->map($site, 'location-page', 33);

    $current = $service->current($site);

    expect($current->keys()->all())->toContain('service-page', 'location-page')
        ->and($current['location-page']->template_id)->toBe(33);
});

it('unmaps a kit (falls back to the kit suggestion)', function () {
    $site = mappingSite();
    $service = app(TemplateMapping::class);
    $service->map($site, 'service-page', 11);

    expect($service->unmap($site, 'service-page'))->toBeTrue()
        ->and($service->resolve($site, 'service-page'))->toBeNull()
        ->and($service->unmap($site, 'service-page'))->toBeFalse(); // already gone
});

it('keeps mappings isolated per tenant', function () {
    $a = mappingSite();
    $b = mappingSite();
    $service = app(TemplateMapping::class);

    $service->map($a, 'service-page', 11);
    $service->map($b, 'service-page', 99);

    expect($service->resolve($a, 'service-page')->template_id)->toBe(11)
        ->and($service->resolve($b, 'service-page')->template_id)->toBe(99);
});
