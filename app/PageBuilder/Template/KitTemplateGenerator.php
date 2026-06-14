<?php

namespace App\PageBuilder\Template;

use App\PageBuilder\Schema\KitSchema;
use App\PageBuilder\Schema\SlotDefinition;
use Illuminate\Support\Str;

/**
 * Builds a structurally-complete, fully-BOUND Elementor template for a kit — every
 * slot present as its own widget, `wf-<slot>`-marked, and bound to its lp/* tag —
 * deterministically from the kit's slot map. This is the #1 artifact (CC builds it
 * bound; the designer only restyles in place). Generating it from the kit keeps the
 * binding ≡ the kit definition, so it never drifts.
 *
 * Two modes from the same map:
 *  - native    → styleable native widgets (Heading / Text Editor / Image) carrying
 *                a `__dynamic__` lp/* dynamic tag. The target — designer restyles it.
 *  - shortcode → a Shortcode widget per slot ([lp_*] …). The proven-live fallback;
 *                less granularly styleable, but the import can't hard-block on it.
 *
 * Output is the Elementor export shape ({version,title,type,content:[…]}); it
 * round-trips through Elementor's Templates → Import.
 */
final class KitTemplateGenerator
{
    public function generate(KitSchema $kit, string $mode = 'native'): array
    {
        $widgets = [];
        foreach ($kit->slots as $slot) {
            $widgets[] = $this->widget($slot, $mode);
        }

        $column = [
            'id' => $this->id('column'),
            'elType' => 'column',
            'settings' => ['_column_size' => 100, '_inline_size' => null],
            'elements' => $widgets,
            'isInner' => false,
        ];

        $section = [
            'id' => $this->id('section'),
            'elType' => 'section',
            'settings' => (object) [],
            'elements' => [$column],
            'isInner' => false,
        ];

        return [
            'version' => '0.4',
            'title' => $this->title($kit),
            'type' => 'section',
            'content' => [$section],
            'page_settings' => (object) [],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function widget(SlotDefinition $slot, string $mode): array
    {
        $base = [
            'id' => $this->id($slot->key),
            'elType' => 'widget',
            'elements' => [],
        ];

        if ($mode === 'shortcode') {
            return [
                ...$base,
                'widgetType' => 'shortcode',
                'settings' => [
                    '_css_classes' => 'wf-'.$slot->key,
                    'shortcode' => $this->shortcode($slot),
                ],
            ];
        }

        [$widgetType, $control, $tag] = $this->nativeMap($slot);

        $settings = [
            '_css_classes' => 'wf-'.$slot->key,
            // A neutral static value so the widget is valid even before the tag
            // resolves; Elementor uses the __dynamic__ value when present.
            $control => $control === 'image' ? ['id' => 0, 'url' => ''] : '',
            '__dynamic__' => [$control => SlotBinding::dynamicTag($tag, $slot->key, $this->id($slot->key.':tag'))],
        ];

        // Brand layer: reference the tenant's Global Kit (system color/typography
        // globals) so the cascade paints it — no hardcoded hex/px.
        $globals = SlotBinding::globalsFor($widgetType);
        if ($globals !== []) {
            $settings['__globals__'] = $globals;
        }

        return [...$base, 'widgetType' => $widgetType, 'settings' => $settings];
    }

    /**
     * content_type → [native widgetType, content control, lp/* tag name]. lp/* tags
     * are TEXT-category (so they bind text controls); the Image widget binds the
     * media-category lp-image tag.
     *
     * @return array{0: string, 1: string, 2: string}
     */
    private function nativeMap(SlotDefinition $slot): array
    {
        return match ($slot->contentType->value) {
            'heading' => ['heading', 'title', 'lp-text'],
            'short_text', 'long_text', 'rich_text' => ['text-editor', 'editor', 'lp-text'],
            'image' => ['image', 'image', 'lp-image'],
            'map' => ['text-editor', 'editor', 'lp-map'],
            'cta' => ['text-editor', 'editor', 'lp-cta'],
            // list / faq / stat / testimonial / gallery — rendered as a block by lp-repeater.
            default => ['text-editor', 'editor', 'lp-repeater'],
        };
    }

    private function shortcode(SlotDefinition $slot): string
    {
        $tag = match ($slot->contentType->value) {
            'image' => 'lp_image',
            'map' => 'lp_map',
            'cta' => 'lp_cta',
            'list', 'faq', 'stat', 'testimonial', 'gallery' => 'lp_repeater',
            default => 'lp_slot',
        };

        return sprintf('[%s key="%s"]', $tag, $slot->key);
    }

    private function title(KitSchema $kit): string
    {
        $base = Str::of($kit->name)->replace('-page', '')->headline();

        return 'Single Page – '.$base;
    }

    /**
     * Deterministic 7-char Elementor element id (unique per distinct seed).
     */
    private function id(string $seed): string
    {
        return substr(md5($seed), 0, 7);
    }
}
