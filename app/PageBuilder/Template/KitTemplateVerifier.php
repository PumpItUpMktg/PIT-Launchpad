<?php

namespace App\PageBuilder\Template;

use App\PageBuilder\Schema\KitSchema;

/**
 * Confirms a stored Elementor kit template binds every REQUIRED slot — the
 * cornerstone of the code-controlled-binding model. It is form-agnostic: a slot
 * counts as bound whether the widget carries a native `__dynamic__` lp/* dynamic
 * tag OR an [lp_*] shortcode. Pure structure parsing — no Elementor runtime — so
 * it guards both the generated artifact AND a designer's restyle (a re-export
 * that accidentally dropped a binding fails here).
 */
final class KitTemplateVerifier
{
    /**
     * @param  array<string, mixed>  $template  the decoded Elementor export
     *                                          (`{content: […]}`) or a raw element list
     */
    public function verify(KitSchema $kit, array $template): TemplateBindingResult
    {
        $bound = array_values(array_unique($this->boundSlotKeys($template)));

        $required = [];
        foreach ($kit->slots as $slot) {
            if ($slot->isRequired()) {
                $required[] = $slot->key;
            }
        }

        return new TemplateBindingResult(
            boundSlots: $bound,
            missingRequired: array_values(array_diff($required, $bound)),
        );
    }

    /**
     * @param  array<string, mixed>  $template
     * @return list<string>
     */
    private function boundSlotKeys(array $template): array
    {
        $elements = $template['content'] ?? $template;
        if (! is_array($elements)) {
            return [];
        }

        $keys = [];
        $this->walk($elements, $keys);

        return $keys;
    }

    /**
     * @param  array<mixed>  $elements
     * @param  list<string>  $keys
     */
    private function walk(array $elements, array &$keys): void
    {
        foreach ($elements as $element) {
            if (! is_array($element)) {
                continue;
            }

            if (isset($element['settings']) && is_array($element['settings'])) {
                $this->scanStrings($element['settings'], $keys);
            }

            if (isset($element['elements']) && is_array($element['elements'])) {
                $this->walk($element['elements'], $keys);
            }
        }
    }

    /**
     * Pull slot keys out of every string leaf in a settings subtree — covers the
     * `__dynamic__` tag value, a shortcode control, or any text control that
     * carries a binding.
     *
     * @param  array<mixed>  $node
     * @param  list<string>  $keys
     */
    private function scanStrings(array $node, array &$keys): void
    {
        foreach ($node as $value) {
            if (is_array($value)) {
                $this->scanStrings($value, $keys);

                continue;
            }
            if (is_string($value)) {
                $this->scan($value, $keys);
            }
        }
    }

    /**
     * @param  list<string>  $keys
     */
    private function scan(string $string, array &$keys): void
    {
        // [lp_slot key="…"] / [lp_repeater …] / [lp_cta …] / [lp_image …] / [lp_map …]
        if (preg_match_all('/\[lp_(?:slot|repeater|cta|image|map)\s+[^\]]*key="([^"]+)"/', $string, $m)) {
            foreach ($m[1] as $key) {
                $keys[] = $key;
            }
        }

        // Native dynamic tag: [elementor-tag … name="lp-…" settings="<url-encoded {slot:…}>"]
        if (preg_match_all('/name="lp-[a-z]+"\s+settings="([^"]*)"/', $string, $m)) {
            foreach ($m[1] as $encoded) {
                if (preg_match('/"slot"\s*:\s*"([^"]+)"/', urldecode($encoded), $sm)) {
                    $keys[] = $sm[1];
                }
            }
        }
    }
}
