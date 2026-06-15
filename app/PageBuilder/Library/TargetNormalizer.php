<?php

namespace App\PageBuilder\Library;

/**
 * Normalize-on-load: rewrites a library block's 0.4 element tree into the
 * live-correct Elementor 4.1.3 shape, applying the deltas from the target profile
 * (config/elementor_target.php) — so reconciliations live in the repo, never in the
 * read-only library, and survive a library regen.
 *
 * The one structural delta today: the library's classic `accordion` (settings.tabs[])
 * → the verified-live `nested-accordion` (settings.items[] titles + one index-paired,
 * LOCKED child container per item → inner column container → text-editor). The 22/26
 * container-composition blocks (heading/text/image/button) are already at the
 * session-captured live keys and pass through unchanged.
 */
final class TargetNormalizer
{
    /** @var array<string, mixed> */
    private array $profile;

    /**
     * @param  array<string, mixed>|null  $profile
     */
    public function __construct(?array $profile = null)
    {
        $this->profile = $profile ?? (array) config('elementor_target', []);
    }

    /**
     * Normalize an element tree (depth-first; children before parents).
     *
     * @param  list<array<string, mixed>>  $elements
     * @return list<array<string, mixed>>
     */
    public function normalize(array $elements): array
    {
        return array_map(fn ($el) => $this->node($el), $elements);
    }

    /**
     * @param  array<string, mixed>  $el
     * @return array<string, mixed>
     */
    private function node(array $el): array
    {
        if (isset($el['elements']) && is_array($el['elements'])) {
            $el['elements'] = $this->normalize($el['elements']);
        }

        // Strip the baked per-block padding so the base wf-* stylesheet's structure
        // density tokens (--wf-pad-block) own it — a `wf-block` container's vertical
        // rhythm is the chosen preset's job, not the library's.
        if (($this->profile['strip_block_padding'] ?? false)
            && ($el['elType'] ?? null) === 'container'
            && $this->isWfBlock($el)) {
            unset($el['settings']['padding']);
        }

        if (($el['elType'] ?? null) === 'widget'
            && ($el['widgetType'] ?? null) === 'accordion'
            && ($this->profile['faq_widget'] ?? null) === 'nested-accordion') {
            return $this->classicToNestedAccordion($el);
        }

        return $el;
    }

    /**
     * @param  array<string, mixed>  $el
     */
    private function isWfBlock(array $el): bool
    {
        $classes = $el['settings']['_css_classes'] ?? '';

        return is_string($classes) && in_array('wf-block', explode(' ', $classes), true);
    }

    /**
     * classic accordion → nested-accordion, preserving the element id and its `wf-*`
     * hook (`_css_classes` / `_title`) so the injection engine still finds it.
     *
     * @param  array<string, mixed>  $el
     * @return array<string, mixed>
     */
    private function classicToNestedAccordion(array $el): array
    {
        $tabs = is_array($el['settings']['tabs'] ?? null) ? $el['settings']['tabs'] : [];

        $items = [];
        $panels = [];
        foreach (array_values($tabs) as $i => $tab) {
            $title = (string) ($tab['tab_title'] ?? '');
            $content = (string) ($tab['tab_content'] ?? '');
            $items[] = [
                'item_title' => $title,
                '_id' => (string) ($tab['_id'] ?? $this->id($el['id'].":item:{$i}")),
            ];
            $panels[] = $this->panel($content, $title, ((string) ($el['id'] ?? 'acc')).":panel:{$i}");
        }

        $settings = ['items' => $items];
        // Carry the block's stable hook + editor label onto the new widget.
        foreach (['_css_classes', '_title'] as $key) {
            if (isset($el['settings'][$key]) && $el['settings'][$key] !== '') {
                $settings[$key] = $el['settings'][$key];
            }
        }

        return [
            'id' => $el['id'] ?? $this->id('acc'),
            'elType' => 'widget',
            'widgetType' => 'nested-accordion',
            'settings' => $settings,
            'elements' => $panels,
            'isInner' => $el['isInner'] ?? false,
        ];
    }

    /**
     * One nested-accordion content panel (the verified-live shape): a LOCKED inner
     * child container (content_width:full, _title) → inner column container → the
     * answer as a text-editor.
     *
     * @return array<string, mixed>
     */
    private function panel(string $contentHtml, string $title, string $seed): array
    {
        $textEditor = [
            'id' => $this->id("{$seed}:text"),
            'elType' => 'widget',
            'widgetType' => 'text-editor',
            'settings' => ['editor' => $contentHtml],
            'elements' => [],
            'isInner' => false,
        ];

        $inner = [
            'id' => $this->id("{$seed}:inner"),
            'elType' => 'container',
            'settings' => ['flex_direction' => 'column'],
            'elements' => [$textEditor],
            'isInner' => true,
        ];

        return [
            'id' => $this->id("{$seed}:container"),
            'elType' => 'container',
            'settings' => ['content_width' => 'full', '_title' => $title],
            'elements' => [$inner],
            'isInner' => true,
            'isLocked' => true,
        ];
    }

    private function id(string $seed): string
    {
        return substr(md5($seed), 0, 7);
    }
}
