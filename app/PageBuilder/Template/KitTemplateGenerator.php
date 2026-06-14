<?php

namespace App\PageBuilder\Template;

use App\Enums\SlotRole;
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
 * The slots are grouped into DESIGNED ZONES — hero / explainer / features / proof /
 * faq / cta — each emitted as its own full-width (stretched) Elementor section with
 * its own content width, vertical rhythm, and alternating surface, so the page
 * reads as distinct zones instead of one narrow column. The hero becomes a two-up
 * (copy + image) when it carries an image. Brand (colors/typography) cascades
 * through the widgets' `__globals__`; the block interiors are styled by the
 * companion stylesheet — this layer is the structure.
 *
 * Two binding modes from the same map:
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
    /**
     * Per-zone layout: boxed content width (px), vertical padding (px), and whether
     * the zone gets the alternating surface tint. Ordering on the page follows the
     * zone's first appearance in the kit's slot order.
     */
    private const ZONES = [
        'hero' => ['width' => 1180, 'pad' => 96],
        'explainer' => ['width' => 760, 'pad' => 72],
        'features' => ['width' => 1180, 'pad' => 80],
        'proof' => ['width' => 1000, 'pad' => 84],
        'faq' => ['width' => 800, 'pad' => 64],
        'cta' => ['width' => 940, 'pad' => 88],
        'body' => ['width' => 860, 'pad' => 64],
    ];

    /** The alternating surface for tinted zones (neutral design chrome, not brand). */
    private const ALT_SURFACE = '#f6f7f9';

    public function generate(KitSchema $kit, string $mode = 'native'): array
    {
        $sections = [];
        $index = 0;
        foreach ($this->zonedSlots($kit) as $zone => $slots) {
            $sections[] = $this->section($zone, $slots, $mode, $index % 2 === 1);
            $index++;
        }

        return [
            'version' => '0.4',
            'title' => $this->title($kit),
            'type' => 'section',
            'content' => $sections,
            'page_settings' => (object) [],
        ];
    }

    /**
     * Group the kit's slots into ordered design zones, preserving kit order within a
     * zone and ordering zones by first appearance.
     *
     * @return array<string, list<SlotDefinition>>
     */
    private function zonedSlots(KitSchema $kit): array
    {
        $zones = [];
        foreach ($kit->slots as $slot) {
            $zones[$this->zoneFor($slot)][] = $slot;
        }

        return $zones;
    }

    private function zoneFor(SlotDefinition $slot): string
    {
        return match ($slot->role) {
            SlotRole::HeroProblem, SlotRole::HeroSolution, SlotRole::Navigation => 'hero',
            SlotRole::BodyExplainer => $slot->isRepeater() ? 'features' : 'explainer',
            SlotRole::Proof => 'proof',
            SlotRole::Faq => 'faq',
            SlotRole::Cta, SlotRole::Contact => 'cta',
            default => 'explainer',
        };
    }

    /**
     * A full-width zone section: stretched to the viewport with a boxed inner width,
     * vertical padding for rhythm, and (on alternating zones) a neutral surface so
     * the page reads as distinct bands. The hero is a two-up (copy | image) when it
     * carries an image; every other zone is a single column.
     *
     * @param  list<SlotDefinition>  $slots
     */
    private function section(string $zone, array $slots, string $mode, bool $alt): array
    {
        $spec = self::ZONES[$zone] ?? self::ZONES['body'];

        $settings = [
            '_css_classes' => 'lp-zone lp-zone--'.$zone,
            'stretch_section' => 'section-stretched',
            'content_width' => ['unit' => 'px', 'size' => $spec['width']],
            'padding' => [
                'unit' => 'px', 'top' => $spec['pad'], 'bottom' => $spec['pad'],
                'left' => 0, 'right' => 0, 'isLinked' => false,
            ],
        ];

        if ($alt) {
            $settings['background_background'] = 'classic';
            $settings['background_color'] = self::ALT_SURFACE;
        }

        return [
            'id' => $this->id('section:'.$zone),
            'elType' => 'section',
            'settings' => $settings,
            'elements' => $zone === 'hero' ? $this->heroColumns($slots, $mode) : $this->singleColumn($zone, $slots, $mode),
            'isInner' => false,
        ];
    }

    /**
     * A single full-width column holding the zone's widgets.
     *
     * @param  list<SlotDefinition>  $slots
     * @return list<array<string, mixed>>
     */
    private function singleColumn(string $zone, array $slots, string $mode): array
    {
        return [$this->column($zone, $slots, $mode, 100)];
    }

    /**
     * The hero as a two-up: copy on the left, image on the right. Falls back to a
     * single column when the hero carries no image.
     *
     * @param  list<SlotDefinition>  $slots
     * @return list<array<string, mixed>>
     */
    private function heroColumns(array $slots, string $mode): array
    {
        $images = array_values(array_filter($slots, fn (SlotDefinition $s) => $s->contentType->value === 'image'));
        $copy = array_values(array_filter($slots, fn (SlotDefinition $s) => $s->contentType->value !== 'image'));

        if ($images === [] || $copy === []) {
            return $this->singleColumn('hero', $slots, $mode);
        }

        return [
            $this->column('hero-copy', $copy, $mode, 60),
            $this->column('hero-media', $images, $mode, 40),
        ];
    }

    /**
     * @param  list<SlotDefinition>  $slots
     * @return array<string, mixed>
     */
    private function column(string $seed, array $slots, string $mode, int $size): array
    {
        $widgets = [];
        foreach ($slots as $slot) {
            $widgets[] = $this->widget($slot, $mode);
        }

        return [
            'id' => $this->id('column:'.$seed),
            'elType' => 'column',
            'settings' => ['_column_size' => $size, '_inline_size' => null],
            'elements' => $widgets,
            'isInner' => false,
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
