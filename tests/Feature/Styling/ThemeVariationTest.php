<?php

use App\Styling\StyleVariation;

/**
 * Locks the shipped block-theme style variations (wordpress-theme/launchpad-blocks/styles/*.json) to the
 * control-plane {@see StyleVariation} token registry. The recommender decides a variation and the
 * Brand step writes its tokens to theme.json; if the two drift, a page would render in different
 * colors than the operator was shown. This is the single-source-of-truth guard across the PHP/WP
 * boundary.
 */
function variationJson(StyleVariation $v): array
{
    $path = base_path("wordpress-theme/launchpad-blocks/styles/{$v->themeVariationSlug()}.json");

    return json_decode((string) file_get_contents($path), true, 512, JSON_THROW_ON_ERROR);
}

function paletteColor(array $themeJson, string $slug): ?string
{
    foreach ($themeJson['settings']['color']['palette'] ?? [] as $entry) {
        if (($entry['slug'] ?? null) === $slug) {
            return (string) $entry['color'];
        }
    }

    return null;
}

it('ships a valid base theme.json (block theme, v3)', function () {
    $theme = json_decode((string) file_get_contents(base_path('wordpress-theme/launchpad-blocks/theme.json')), true, 512, JSON_THROW_ON_ERROR);

    expect($theme['version'])->toBe(3)
        ->and($theme['settings']['appearanceTools'] ?? null)->toBeTrue()
        // The token vocabulary the patterns bind to exists.
        ->and(paletteColor($theme, 'primary'))->not->toBeNull()
        ->and(paletteColor($theme, 'accent'))->not->toBeNull()
        ->and(paletteColor($theme, 'on-accent'))->not->toBeNull();
});

it('is a self-contained block theme (declared + has its own templates, no parent required)', function () {
    $css = (string) file_get_contents(base_path('wordpress-theme/launchpad-blocks/style.css'));

    expect($css)->toContain('Theme Name: Launchpad')
        // Standalone: no parent-theme dependency (a missing parent blocks activation on a tenant).
        ->and($css)->not->toContain('Template:');

    // A block theme needs its own templates + parts (it no longer inherits them).
    foreach (['templates/index.html', 'templates/page.html', 'parts/header.html', 'parts/footer.html'] as $file) {
        expect(file_exists(base_path("wordpress-theme/launchpad-blocks/{$file}")))->toBeTrue();
    }
});

it('each style variation matches its control-plane StyleVariation tokens exactly', function (StyleVariation $variation) {
    $json = variationJson($variation);
    $tokens = $variation->tokens();

    expect($json['title'])->toBe($variation->label())
        ->and(paletteColor($json, 'primary'))->toBe($tokens['primary'])
        ->and(paletteColor($json, 'accent'))->toBe($tokens['accent'])
        ->and($json['settings']['custom']['radius'])->toBe($tokens['radius'])
        ->and($json['settings']['custom']['headingLetterSpacing'])->toBe($tokens['heading_letter_spacing'])
        ->and($json['settings']['custom']['headingWeight'])->toBe((string) $tokens['heading_weight']);

    // The full six-role palette is written: the button token too, matching the enum palette.
    expect(paletteColor($json, 'button'))->toBe($variation->palette()['button'])
        ->and(paletteColor($json, 'on-button'))->toBe($variation->palette()['on_button'])
        ->and(paletteColor($json, 'base'))->toBe($variation->palette()['base'])
        ->and(paletteColor($json, 'surface'))->toBe($variation->palette()['surface']);

    // The heading font family declares the variation's typeface.
    $headingFamily = collect($json['settings']['typography']['fontFamilies'])
        ->firstWhere('slug', 'heading')['fontFamily'] ?? '';
    expect($headingFamily)->toContain($tokens['heading_font']);
})->with(collect(StyleVariation::cases())->mapWithKeys(fn (StyleVariation $v): array => [$v->value => $v])->all());

it('bundles the heading webfont locally for each variation (fontFace → an existing woff2)', function (StyleVariation $variation) {
    $json = variationJson($variation);
    $heading = collect($json['settings']['typography']['fontFamilies'])->firstWhere('slug', 'heading');

    expect($heading['fontFace'] ?? null)->toBeArray()->not->toBeEmpty();

    $src = $heading['fontFace'][0]['src'][0] ?? '';
    expect($src)->toStartWith('file:./assets/fonts/')->toEndWith('.woff2');

    // The referenced file is actually bundled (self-hosted, not a CDN link).
    $path = base_path('wordpress-theme/launchpad-blocks/'.substr($src, strlen('file:./')));
    expect(file_exists($path))->toBeTrue();
})->with(collect(StyleVariation::cases())->mapWithKeys(fn (StyleVariation $v): array => [$v->value => $v])->all());

it('bundles the Inter body font in the base theme', function () {
    $theme = json_decode((string) file_get_contents(base_path('wordpress-theme/launchpad-blocks/theme.json')), true, 512, JSON_THROW_ON_ERROR);
    $body = collect($theme['settings']['typography']['fontFamilies'])->firstWhere('slug', 'body');

    expect($body['fontFamily'])->toContain('Inter')
        ->and($body['fontFace'])->toBeArray()->not->toBeEmpty()
        ->and(file_exists(base_path('wordpress-theme/launchpad-blocks/assets/fonts/inter-400.woff2')))->toBeTrue();
});

it('covers every StyleVariation with a shipped theme file (no orphans either way)', function () {
    $files = glob(base_path('wordpress-theme/launchpad-blocks/styles/*.json'));
    $slugs = array_map(fn (string $f): string => basename($f, '.json'), $files ?: []);
    $variations = array_map(fn (StyleVariation $v): string => $v->themeVariationSlug(), StyleVariation::cases());

    sort($slugs);
    sort($variations);

    expect($slugs)->toBe($variations);
});
