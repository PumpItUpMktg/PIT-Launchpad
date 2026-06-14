<?php

namespace App\PageBuilder\Template;

use App\PageBuilder\Schema\KitSchema;
use App\PageBuilder\Schema\SlotDefinition;

/**
 * The PRODUCTION binding path: attach lp/* data-bindings to the DESIGNER's own
 * styled Elementor template — bind the design, don't regenerate it. The designer
 * builds the layout once (their widgets, Pro widgets, styling) and marks each
 * content widget `wf-<slot>`; this walks the exported `_elementor_data`, finds each
 * marked widget, and adds a `__dynamic__` lp/* tag on its content control. Nothing
 * else is touched — every other setting (typography, spacing, colors, layout,
 * unmarked decorative widgets) is preserved exactly.
 *
 * A `wf-` marker on a widget type whose content control isn't known is left unbound
 * (never guessed); KitTemplateVerifier then surfaces the unbound required slot so it
 * is fixed visibly, not silently. (KitTemplateGenerator remains the from-scratch
 * FALLBACK for tenants with no custom design.)
 */
final class KitTemplateBinder
{
    /**
     * @param  array<string, mixed>  $template  the designer's decoded Elementor export
     * @return array<string, mixed> the same template with bindings injected
     */
    public function bind(KitSchema $kit, array $template): array
    {
        $slots = [];
        foreach ($kit->slots as $slot) {
            $slots[$slot->key] = $slot;
        }

        if (isset($template['content']) && is_array($template['content'])) {
            $template['content'] = $this->walk($template['content'], $slots);

            return $template;
        }

        return $this->walk($template, $slots);
    }

    /**
     * @param  array<mixed>  $elements
     * @param  array<string, SlotDefinition>  $slots
     * @return array<mixed>
     */
    private function walk(array $elements, array $slots): array
    {
        foreach ($elements as $i => $element) {
            if (! is_array($element)) {
                continue;
            }

            if (($element['elType'] ?? null) === 'widget') {
                $element = $this->bindWidget($element, $slots);
            }

            if (isset($element['elements']) && is_array($element['elements'])) {
                $element['elements'] = $this->walk($element['elements'], $slots);
            }

            $elements[$i] = $element;
        }

        return $elements;
    }

    /**
     * @param  array<string, mixed>  $element
     * @param  array<string, SlotDefinition>  $slots
     * @return array<string, mixed>
     */
    private function bindWidget(array $element, array $slots): array
    {
        $classes = (string) ($element['settings']['_css_classes'] ?? '');
        if (! preg_match('/(?:^|\s)wf-([a-z0-9_]+)(?:\s|$)/i', $classes, $m)) {
            return $element; // not a slot widget — designer styling, untouched
        }

        $slot = $slots[$m[1]] ?? null;
        if ($slot === null) {
            return $element; // marks an unknown slot — leave as the designer set it
        }

        $control = SlotBinding::controlForWidget((string) ($element['widgetType'] ?? ''));
        if ($control === null) {
            return $element; // unknown widget control — never guess; verifier flags it
        }

        // Add ONLY the binding; every other setting is preserved as authored.
        $element['settings']['__dynamic__'][$control] = SlotBinding::dynamicTag(
            SlotBinding::tagName($slot->contentType),
            $slot->key,
            SlotBinding::id($slot->key.':bind'),
        );

        return $element;
    }
}
