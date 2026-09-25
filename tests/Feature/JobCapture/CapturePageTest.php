<?php

test('the capture PWA shell renders', function () {
    $this->get('/capture')
        ->assertOk()
        ->assertSee('Job Capture', false)
        ->assertSee('/capture/manifest.webmanifest', false)
        ->assertSee('/capture/sw.js', false);
});

test('the shell exposes the library-upload path (photo capture OR upload) and the manual sync control', function () {
    $this->get('/capture')
        ->assertOk()
        ->assertSee('id="upload-lib"', false)     // gallery upload beside tap-to-shoot
        ->assertSee('id="lib-input"', false)
        ->assertSee('id="sync-now"', false)       // manual retry for a stuck queue
        ->assertSee('drainQueue({ interactive: true })', false);
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
    $response = $this->get('/capture/sw.js')->assertOk()->assertSee('job-capture-v3', false);

    expect($response->headers->get('Content-Type'))->toContain('application/javascript')
        ->and($response->headers->get('Service-Worker-Allowed'))->toBe('/capture');
});

test('a deploy reaches an installed phone: the shell is network-first and revalidated, and the page reloads under a new worker', function () {
    // The worker fetches the live shell and only falls back to its cache offline — never cache-first.
    $sw = $this->get('/capture/sw.js')->assertOk()->getContent();
    expect($sw)->toContain('networkFirst(event.request')
        ->not->toContain("caches.match('/capture').then((cached) => cached || fetch");

    // The shell itself is never pinned by the HTTP cache, and swaps to the new worker's copy on takeover.
    $shell = $this->get('/capture')->assertOk()->assertHeader('Cache-Control', 'no-cache, private');
    expect($shell->getContent())->toContain('controllerchange');
});
