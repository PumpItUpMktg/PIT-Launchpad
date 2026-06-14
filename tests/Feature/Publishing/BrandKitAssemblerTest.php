<?php

use App\Models\Site;
use App\Models\SiteBranding;
use App\Publishing\BrandKitAssembler;

function brandingForSite(array $attrs): Site
{
    $site = Site::factory()->create();
    SiteBranding::withoutGlobalScopes()->create(array_merge(['site_id' => $site->id], $attrs));

    return $site;
}

it('maps the intake palette to system color slots', function () {
    $site = brandingForSite(['palette' => ['primary' => '#0F62FE', 'accent' => '#FF6F00']]);

    $payload = (new BrandKitAssembler)->forSite($site->id);

    expect($payload['colors'])->toBe(['primary' => '#0F62FE', 'accent' => '#FF6F00']);
});

it('maps intake typography role names (heading/body) to elementor slots (primary/text)', function () {
    $site = brandingForSite(['typography' => ['heading' => 'Inter', 'body' => 'Georgia']]);

    $payload = (new BrandKitAssembler)->forSite($site->id);

    expect($payload['fonts'])->toBe([
        'primary' => ['family' => 'Inter'],
        'text' => ['family' => 'Georgia'],
    ]);
});

it('normalizes a {family, weight} typography shape', function () {
    $site = brandingForSite(['typography' => ['heading' => ['family' => 'Inter', 'weight' => '700']]]);

    $payload = (new BrandKitAssembler)->forSite($site->id);

    expect($payload['fonts']['primary'])->toBe(['family' => 'Inter', 'weight' => '700']);
});

it('keeps only the four known color slots and drops empties', function () {
    $site = brandingForSite(['palette' => ['primary' => '#111', 'brand_x' => '#999', 'text' => '']]);

    $payload = (new BrandKitAssembler)->forSite($site->id);

    expect($payload['colors'])->toBe(['primary' => '#111']);
});

it('returns a payload carrying just the captured primary color (today\'s thin intake)', function () {
    // The wizard captures only palette.primary right now — the push must still
    // carry it (the cascade lights up the rest as intake grows).
    $site = brandingForSite(['palette' => ['primary' => '#0F62FE']]);

    $payload = (new BrandKitAssembler)->forSite($site->id);

    expect($payload)->toBe(['colors' => ['primary' => '#0F62FE'], 'fonts' => []]);
});

it('returns null when the site has no branding row', function () {
    $site = Site::factory()->create();

    expect((new BrandKitAssembler)->forSite($site->id))->toBeNull();
});

it('returns null when branding captured neither colors nor fonts', function () {
    $site = brandingForSite(['palette' => [], 'typography' => []]);

    expect((new BrandKitAssembler)->forSite($site->id))->toBeNull();
});
