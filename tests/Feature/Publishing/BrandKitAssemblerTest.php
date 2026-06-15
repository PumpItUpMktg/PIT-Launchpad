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

    expect($payload)->toBe([
        'colors' => ['primary' => '#0F62FE'],
        'fonts' => [],
        'wf_tokens' => ['--wf-color-primary' => '#0F62FE'],
        'structure' => 'trust', // default when unset
    ]);
});

it('maps the palette + typography to the native --wf-* token set', function () {
    $site = brandingForSite([
        'palette' => [
            'primary' => '#1B3A5B', 'secondary' => '#3E6E9E', 'accent' => '#E8A23D',
            'text' => '#1A1A1A', 'text_muted' => '#5B6470', 'bg' => '#FFFFFF',
            'bg_alt' => '#F4F6F8', 'border' => '#E2E6EB',
        ],
        'typography' => ['heading' => 'Archivo', 'body' => ['family' => 'Inter', 'weight' => '400']],
    ]);

    expect((new BrandKitAssembler)->forSite($site->id)['wf_tokens'])->toBe([
        '--wf-color-primary' => '#1B3A5B',
        '--wf-color-secondary' => '#3E6E9E',
        '--wf-color-accent' => '#E8A23D',
        '--wf-color-text' => '#1A1A1A',
        '--wf-color-text-muted' => '#5B6470',
        '--wf-color-bg' => '#FFFFFF',
        '--wf-color-bg-alt' => '#F4F6F8',
        '--wf-color-border' => '#E2E6EB',
        '--wf-font-heading' => 'Archivo',
        '--wf-font-body' => 'Inter', // family only (weight lives in the structure tokens)
    ]);
});

it('carries the chosen structure preset, defaulting/validating to trust', function () {
    $bold = brandingForSite(['palette' => ['primary' => '#111'], 'structure_preset' => 'bold']);
    expect((new BrandKitAssembler)->forSite($bold->id)['structure'])->toBe('bold');

    $garbage = brandingForSite(['palette' => ['primary' => '#111'], 'structure_preset' => 'nope']);
    expect((new BrandKitAssembler)->forSite($garbage->id)['structure'])->toBe('trust');
});

it('returns null when the site has no branding row', function () {
    $site = Site::factory()->create();

    expect((new BrandKitAssembler)->forSite($site->id))->toBeNull();
});

it('returns null when branding captured neither colors nor fonts', function () {
    $site = brandingForSite(['palette' => [], 'typography' => []]);

    expect((new BrandKitAssembler)->forSite($site->id))->toBeNull();
});
