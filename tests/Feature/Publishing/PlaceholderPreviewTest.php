<?php

use App\Enums\ContentSource;
use App\Publishing\MetaBlobAssembler;
use App\Publishing\PlaceholderSlots;
use App\Publishing\RenderCoordinator;
use Tests\Support\PublishHarness;

/** Top-level wf-block-* classes in an elementor_data tree (the structural skeleton). */
function wfBlocks(array $elements): array
{
    $blocks = [];
    $walk = function (array $els) use (&$walk, &$blocks): void {
        foreach ($els as $el) {
            $cls = is_array($el) ? (string) ($el['settings']['_css_classes'] ?? '') : '';
            foreach (explode(' ', $cls) as $c) {
                if (str_starts_with($c, 'wf-block-')) {
                    $blocks[] = $c;
                }
            }
            if (is_array($el) && ! empty($el['elements'])) {
                $walk($el['elements']);
            }
        }
    };
    $walk($elements);
    sort($blocks);

    return $blocks;
}

test('placeholder preview is the SAME skeleton as generated — only content swaps', function () {
    PublishHarness::fakeAdapters();
    $site = PublishHarness::site();
    $content = PublishHarness::approvedPage($site);
    $jobs = app(RenderCoordinator::class)->render($content)->jobs;

    $assembler = app(MetaBlobAssembler::class);
    $generated = $assembler->assemble($content->fresh(), $jobs, ContentSource::Generated);
    $placeholder = $assembler->assemble($content->fresh(), $jobs, ContentSource::Placeholder);

    // Service pages are pure blocks now — both bodies are core-block post_content, no Elementor tree.
    expect($generated['elementor_data'])->toBe([])
        ->and($placeholder['elementor_data'])->toBe([])
        ->and($placeholder['kit'])->toBe($generated['kit']);

    // Placeholder fills EVERY slot, so it renders the full block skeleton (hero + overview + features +
    // FAQ) — a superset of the sparse generated page, which prunes unfed sections (the harness page
    // feeds no faq / overview slots, so those sections are absent on the generated body).
    expect($placeholder['post_content'])
        ->toContain('lp-hero')->toContain('lp-features')->toContain('lp-prose')->toContain('lp-faq-list');
    expect($generated['post_content'])
        ->toContain('lp-hero')->toContain('lp-features')   // fed slots render
        ->not->toContain('lp-faq-list');                   // no faq slot fed → pruned

    // Content swapped: stand-in copy + the placeholder image box, not the real ones.
    expect($placeholder['slot_payload']['hero_problem'])->not->toBe($generated['slot_payload']['hero_problem'])
        ->and($placeholder['images']['hero_image']['url'])->toStartWith('data:image/svg+xml')
        ->and($generated['images']['hero_image']['url'])->not->toStartWith('data:image/svg+xml');
});

test('placeholder slots are length-representative and carry a labeled form box', function () {
    $kit = PublishHarness::site(); // boot
    $content = PublishHarness::approvedPage($kit);
    $schema = $content->wireframeKit->schema();

    $slots = (new PlaceholderSlots)->forSchema($schema);

    expect((string) $slots['hero_problem'])->not->toBe('')
        ->and(strlen((string) $slots['hero_problem']))->toBeGreaterThan(12)   // a real line, not one word
        ->and($slots['cta']['form_embed'])->toContain('Form embed')           // labeled placeholder box
        ->and($slots['cta']['phone'])->toBe('(555) 123-4567');
});

test('placeholder defaults are off — a normal assemble is unaffected', function () {
    PublishHarness::fakeAdapters();
    $site = PublishHarness::site();
    $content = PublishHarness::approvedPage($site);
    $jobs = app(RenderCoordinator::class)->render($content)->jobs;

    // Default source = generated → real hero image, real copy.
    $payload = app(MetaBlobAssembler::class)->assemble($content->fresh(), $jobs);

    expect($payload['images']['hero_image']['url'])->not->toStartWith('data:image/svg+xml')
        ->and($payload['slot_payload']['hero_problem'])->toContain('Leaking');
});
