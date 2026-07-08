<?php

use App\Enums\ContentSource;
use App\Models\PageConfig;
use App\Publishing\MetaBlobAssembler;
use App\Publishing\RenderCoordinator;
use Tests\Support\PublishHarness;

/** Find an element anywhere in the tree carrying the exact class token. */
function elByClass(array $elements, string $token): ?array
{
    foreach ($elements as $el) {
        if (! is_array($el)) {
            continue;
        }
        $cls = (string) ($el['settings']['_css_classes'] ?? '');
        if (in_array($token, explode(' ', $cls), true)) {
            return $el;
        }
        if (! empty($el['elements'])) {
            $hit = elByClass($el['elements'], $token);
            if ($hit !== null) {
                return $hit;
            }
        }
    }

    return null;
}

test('hero_variant=form swaps the standard hero for the media form hero', function () {
    PublishHarness::fakeAdapters();
    $site = PublishHarness::site();
    $content = PublishHarness::approvedPage($site);
    PageConfig::create([
        'site_id' => $site->id, 'content_id' => $content->id,
        'hero_variant' => 'form',
        'phone_override' => '+15551234567',
        'form_embed' => '<iframe src="https://ghl.example/form/abc"></iframe>',
    ]);

    $data = app(MetaBlobAssembler::class)->assemble(
        $content->fresh(), app(RenderCoordinator::class)->render($content)->jobs,
    )['elementor_data'];

    // The standard hero is replaced by the form hero (exact class tokens).
    expect(elByClass($data, 'wf-block-hero-form'))->not->toBeNull()
        ->and(elByClass($data, 'wf-block-hero'))->toBeNull();

    // Media image + copy + form card with the real embed + the "or call" phone link.
    expect(elByClass($data, 'wf-hero-image'))->not->toBeNull();
    $form = elByClass($data, 'wf-hero-form');
    expect($form['settings']['html'])->toContain('ghl.example');
    $call = elByClass($data, 'wf-hero-call');
    expect($call['settings']['editor'])->toContain('tel:+15551234567');
});

test('without a form config the service page migrates to blocks (no Elementor hero at all)', function () {
    PublishHarness::fakeAdapters();
    $site = PublishHarness::site();
    $content = PublishHarness::approvedPage($site);

    $blob = app(MetaBlobAssembler::class)->assemble(
        $content->fresh(), app(RenderCoordinator::class)->render($content)->jobs,
    );

    // No form-hero opt-in → the service page is pure blocks: a core-block hero, no Elementor tree, so
    // neither the standard nor the form Elementor hero is present.
    expect($blob['elementor_data'])->toBe([])
        ->and($blob['post_content'])->toBeString()->toContain('lp-hero')
        ->and(elByClass($blob['elementor_data'], 'wf-block-hero'))->toBeNull()
        ->and(elByClass($blob['elementor_data'], 'wf-block-hero-form'))->toBeNull();
});

test('the form hero shows the placeholder box in placeholder mode (no embed needed)', function () {
    PublishHarness::fakeAdapters();
    $site = PublishHarness::site();
    $content = PublishHarness::approvedPage($site);
    PageConfig::create(['site_id' => $site->id, 'content_id' => $content->id, 'hero_variant' => 'form']); // no form_embed

    $data = app(MetaBlobAssembler::class)->assemble(
        $content->fresh(), app(RenderCoordinator::class)->render($content)->jobs, ContentSource::Placeholder,
    )['elementor_data'];

    expect(elByClass($data, 'wf-block-hero-form'))->not->toBeNull()
        ->and(elByClass($data, 'wf-hero-form')['settings']['html'])->toContain('Form'); // labeled box, no real embed
});
