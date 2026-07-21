<?php

use App\Models\Location;
use App\Models\PageConfig;
use App\Publishing\MetaBlobAssembler;
use App\Publishing\RenderCoordinator;
use Tests\Support\PublishHarness;

test('the per-page config overrides phone, form embed, and hero image — and survives repush', function () {
    PublishHarness::fakeAdapters();
    $site = PublishHarness::site();
    Location::factory()->create(['site_id' => $site->id, 'phone' => '+15550001111']); // §1 default
    $content = PublishHarness::approvedPage($site);

    PageConfig::create([
        'site_id' => $site->id,
        'content_id' => $content->id,
        'phone_override' => '+15559998888',
        'form_embed' => '<iframe src="https://ghl.example/form/abc"></iframe>',
        'hero_image_override' => 'https://r2.example/operator-hero.jpg',
    ]);

    $outcome = app(RenderCoordinator::class)->render($content);
    $payload = app(MetaBlobAssembler::class)->assemble($content->fresh(), $outcome->jobs);

    expect($payload['slot_payload']['cta']['phone'])->toBe('+1 (555) 999-8888')        // override wins over §1 phone
        ->and($payload['slot_payload']['cta']['tel'])->toBe('tel:+15559998888')
        ->and($payload['slot_payload']['cta']['form_embed'])->toBe('<iframe src="https://ghl.example/form/abc"></iframe>')
        ->and($payload['images']['hero_image']['url'])->toBe('https://r2.example/operator-hero.jpg');

    // Repush proof — a fresh assembler re-reads PageConfig and re-injects verbatim.
    $repush = app()->make(MetaBlobAssembler::class)->assemble($content->fresh(), $outcome->jobs);
    expect($repush['slot_payload']['cta']['phone'])->toBe('+1 (555) 999-8888')
        ->and($repush['images']['hero_image']['url'])->toBe('https://r2.example/operator-hero.jpg');
});

test('a phone override resolves the cta even when the site has no location', function () {
    PublishHarness::fakeAdapters();
    $site = PublishHarness::site(); // no Location
    $content = PublishHarness::approvedPage($site);
    PageConfig::create(['site_id' => $site->id, 'content_id' => $content->id, 'phone_override' => '+15551234567']);

    $payload = app(MetaBlobAssembler::class)->assemble($content->fresh(), app(RenderCoordinator::class)->render($content)->jobs);

    expect($payload['slot_payload']['cta']['phone'])->toBe('+1 (555) 123-4567');
});

test('without a page config the cta falls back to the §1 location phone (no override)', function () {
    PublishHarness::fakeAdapters();
    $site = PublishHarness::site();
    Location::factory()->create(['site_id' => $site->id, 'phone' => '+15550001111']);
    $content = PublishHarness::approvedPage($site);

    $payload = app(MetaBlobAssembler::class)->assemble($content->fresh(), app(RenderCoordinator::class)->render($content)->jobs);

    expect($payload['slot_payload']['cta']['phone'])->toBe('+1 (555) 000-1111');
});
