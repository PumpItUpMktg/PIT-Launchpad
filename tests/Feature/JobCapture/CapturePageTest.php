<?php

test('the capture PWA shell renders', function () {
    $this->get('/capture')
        ->assertOk()
        ->assertSee('Job Capture', false)
        ->assertSee('/capture/manifest.webmanifest', false)
        ->assertSee('/capture/sw.js', false);
});

test('the web app manifest is served with the manifest content type and PWA fields', function () {
    $response = $this->get('/capture/manifest.webmanifest')->assertOk();

    expect($response->headers->get('Content-Type'))->toContain('application/manifest+json');

    $response->assertJsonPath('start_url', '/capture')
        ->assertJsonPath('scope', '/capture')
        ->assertJsonPath('display', 'standalone')
        ->assertJsonPath('icons.0.src', '/capture-icon.svg');
});

test('the service worker is served as javascript, scoped to /capture', function () {
    $response = $this->get('/capture/sw.js')->assertOk()->assertSee('job-capture-v1', false);

    expect($response->headers->get('Content-Type'))->toContain('application/javascript')
        ->and($response->headers->get('Service-Worker-Allowed'))->toBe('/capture');
});
