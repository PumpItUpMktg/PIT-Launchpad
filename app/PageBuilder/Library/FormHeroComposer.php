<?php

namespace App\PageBuilder\Library;

/**
 * The form-hero variant (PageConfig.hero_variant = form): a media surface — full-bleed
 * hero image with a left scrim (CSS) and fixed on-media white text — with the copy on
 * the left and the form embed in a contained light card on the right. It REPLACES the
 * standard wf-block-hero in a composed tree, reusing the same hero slot values; the
 * `.wf-block-hero-form` CSS owns the scrim, on-media color, and responsive stack.
 *
 * Built as native Elementor widgets (same shapes as the library): heading=title,
 * text-editor=editor, image=image{url,id}, html=html (the cross-origin form iframe,
 * styled in GHL — not by our tokens). The form embed is passed in (the real embed or
 * the placeholder box).
 */
final class FormHeroComposer
{
    /**
     * Replace the wf-block-hero in $tree with the form hero. No hero found → unchanged.
     *
     * @param  list<array<string, mixed>>  $tree
     * @param  array<string, mixed>  $slots
     * @param  array<string, array<string, mixed>>  $images
     * @return list<array<string, mixed>>
     */
    public function swapHero(array $tree, array $slots, array $images, ?string $formEmbed, ?string $phone, ?string $tel): array
    {
        $block = $this->build($slots, $images, $formEmbed, $phone, $tel);

        $swapped = false;
        $out = [];
        foreach ($tree as $el) {
            if (! $swapped && $this->isHero($el)) {
                $out[] = $block;
                $swapped = true;

                continue;
            }
            $out[] = $el;
        }

        // No hero present (shouldn't happen for a service page) → prepend the form hero.
        if (! $swapped) {
            array_unshift($out, $block);
        }

        return $out;
    }

    /**
     * @param  array<string, mixed>  $el
     */
    private function isHero(array $el): bool
    {
        $cls = $el['settings']['_css_classes'] ?? '';

        return is_string($cls) && in_array('wf-block-hero', explode(' ', $cls), true);
    }

    /**
     * @param  array<string, mixed>  $slots
     * @param  array<string, array<string, mixed>>  $images
     * @return array<string, mixed>
     */
    private function build(array $slots, array $images, ?string $formEmbed, ?string $phone, ?string $tel): array
    {
        $copy = [];

        $eyebrow = $this->str($slots['hero_eyebrow'] ?? null);
        if ($eyebrow !== null) {
            $copy[] = $this->heading($eyebrow, 'wf-hero-eyebrow', 'fh:eyebrow');
        }
        $copy[] = $this->heading($this->str($slots['hero_problem'] ?? null) ?? 'Get started today', 'wf-hero-headline', 'fh:h1');
        $sub = $this->str($slots['hero_solution'] ?? null);
        if ($sub !== null) {
            $copy[] = $this->textEditor($sub, 'wf-hero-subhead', 'fh:sub');
        }
        if ($phone !== null && trim($phone) !== '') {
            $href = $tel !== null && trim($tel) !== '' ? $tel : 'tel:'.preg_replace('/[^0-9+]/', '', $phone);
            $copy[] = $this->textEditor('or call <a href="'.e($href).'">'.e($phone).'</a>', 'wf-hero-call', 'fh:call');
        }

        $formCard = $this->htmlWidget(
            is_string($formEmbed) && trim($formEmbed) !== '' ? $formEmbed : '<div class="lp-form-placeholder">Form</div>',
            'wf-hero-form',
            'fh:form',
        );

        $row = $this->container('fh:row', 'wf-hero-form-content', [
            $this->container('fh:copy', 'wf-hero-copy', $copy, 'column'),
            $this->container('fh:card', 'wf-hero-form-card', [$formCard], 'column'),
        ], 'row');

        $children = [];
        $heroImage = $this->str($images['hero_image']['url'] ?? null);
        if ($heroImage !== null) {
            $children[] = $this->image($heroImage, (string) ($images['hero_image']['alt'] ?? ''), 'wf-hero-image', 'fh:img');
        }
        $children[] = $row;

        return [
            'id' => $this->id('fh:block'),
            'elType' => 'container',
            'isInner' => false,
            'settings' => ['content_width' => 'full', '_css_classes' => 'wf-block wf-block-hero-form'],
            'elements' => $children,
        ];
    }

    /**
     * @param  list<array<string, mixed>>  $elements
     * @return array<string, mixed>
     */
    private function container(string $seed, string $class, array $elements, string $direction = 'column'): array
    {
        return [
            'id' => $this->id($seed),
            'elType' => 'container',
            'isInner' => true,
            'settings' => ['flex_direction' => $direction, '_css_classes' => $class],
            'elements' => $elements,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function heading(string $title, string $class, string $seed): array
    {
        return $this->widget('heading', ['title' => $title, '_css_classes' => $class], $seed);
    }

    /**
     * @return array<string, mixed>
     */
    private function textEditor(string $html, string $class, string $seed): array
    {
        return $this->widget('text-editor', ['editor' => $html, '_css_classes' => $class], $seed);
    }

    /**
     * @return array<string, mixed>
     */
    private function htmlWidget(string $html, string $class, string $seed): array
    {
        return $this->widget('html', ['html' => $html, '_css_classes' => $class], $seed);
    }

    /**
     * @return array<string, mixed>
     */
    private function image(string $url, string $alt, string $class, string $seed): array
    {
        return $this->widget('image', ['image' => ['url' => $url, 'id' => '', 'alt' => $alt], '_css_classes' => $class], $seed);
    }

    /**
     * @param  array<string, mixed>  $settings
     * @return array<string, mixed>
     */
    private function widget(string $type, array $settings, string $seed): array
    {
        return [
            'id' => $this->id($seed),
            'elType' => 'widget',
            'widgetType' => $type,
            'settings' => $settings,
            'elements' => [],
            'isInner' => false,
        ];
    }

    private function str(mixed $value): ?string
    {
        if (! is_string($value)) {
            return null;
        }
        $trimmed = trim($value);

        return $trimmed === '' ? null : $trimmed;
    }

    private function id(string $seed): string
    {
        return substr(md5($seed.microtime()), 0, 7);
    }
}
