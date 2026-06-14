<?php

use App\PageBuilder\Template\KitTemplateArtifacts;

function artifactsDir(): string
{
    $dir = sys_get_temp_dir().'/lp-artifacts-'.uniqid();
    mkdir($dir, 0777, true);

    return $dir;
}

it('prefers a bound artifact over the generator fallback', function () {
    $dir = artifactsDir();
    file_put_contents($dir.'/service-page.native.elementor.json', json_encode(['content' => [['k' => 'native']]]));
    file_put_contents($dir.'/service-page.bound.elementor.json', json_encode(['content' => [['k' => 'bound']]]));

    $artifacts = new KitTemplateArtifacts($dir);

    expect($artifacts->path('service-page'))->toEndWith('service-page.bound.elementor.json')
        ->and($artifacts->load('service-page')['content'][0]['k'])->toBe('bound');
});

it('falls back to the requested mode when no bound artifact exists', function () {
    $dir = artifactsDir();
    file_put_contents($dir.'/service-page.shortcode.elementor.json', json_encode(['content' => [['k' => 'sc']]]));

    $artifacts = new KitTemplateArtifacts($dir);

    expect($artifacts->path('service-page', 'shortcode'))->toEndWith('service-page.shortcode.elementor.json')
        ->and($artifacts->path('service-page', 'native'))->toBeNull();
});

it('lists the distinct kits that have an artifact', function () {
    $dir = artifactsDir();
    file_put_contents($dir.'/service-page.native.elementor.json', '{}');
    file_put_contents($dir.'/service-page.shortcode.elementor.json', '{}');
    file_put_contents($dir.'/location-page.bound.elementor.json', '{}');

    expect((new KitTemplateArtifacts($dir))->availableKits())->toBe(['location-page', 'service-page']);
});

it('returns null for a missing or unparseable artifact', function () {
    $dir = artifactsDir();
    file_put_contents($dir.'/broken.native.elementor.json', 'not json');

    $artifacts = new KitTemplateArtifacts($dir);

    expect($artifacts->load('absent'))->toBeNull()
        ->and($artifacts->load('broken'))->toBeNull();
});
