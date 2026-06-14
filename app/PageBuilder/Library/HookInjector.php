<?php

namespace App\PageBuilder\Library;

use InvalidArgumentException;

/**
 * Injects resolved §3a content into a normalized library page's `wf-*` hooks and
 * produces a clean, live-ready element tree. The rules (agreed for the migration):
 *
 *  - SET a fed hook by its mode (text → heading.title / text-editor.editor; image →
 *    image.image; button → button.text+link), asserting the value's mode against the
 *    sidecar type (no image into a heading hook) and warning on char-range overflow —
 *    the sidecar is the runtime contract.
 *  - HIDE any UNFED per-tenant content hook (so a PLACEHOLDER image or "Reason 1" /
 *    "Value 1" placeholder never ships) — EXCEPT static `*-heading` section titles
 *    ("Why choose us", "Frequently asked questions"), which are design chrome and stay.
 *  - DROP whole blocks declared content-less on this page (jobs, proof-strip logos).
 *  - PRUNE containers left with no widget descendants (empty card boxes collapse).
 *  - FAQ: rebuild the wf-faq nested-accordion from the faq list (questions through
 *    plainTitle — the #115 emphasis-strip — so the asterisk bug can't return here).
 */
final class HookInjector
{
    /** @var list<string> */
    private array $warnings = [];

    public function __construct(private readonly Sidecar $sidecar) {}

    /**
     * @param  list<array<string, mixed>>  $elements  the normalized page element tree
     * @param  array<string, array{mode: string, value: mixed}>  $values  hook => {mode,value}
     * @param  list<string>  $dropBlocks  wf-block-* classes to remove entirely
     * @param  list<array{question?: string, answer?: string}>  $faq
     * @param  array<string, string>  $staticHeadings  *-heading hook => canonical label
     * @return list<array<string, mixed>>
     */
    public function inject(array $elements, array $values, array $dropBlocks = [], array $faq = [], array $staticHeadings = []): array
    {
        $this->warnings = [];
        $out = [];
        foreach ($elements as $el) {
            $node = $this->transform($el, $values, $dropBlocks, $faq, $staticHeadings);
            if ($node !== null) {
                $out[] = $node;
            }
        }

        return $out;
    }

    /**
     * @return list<string>
     */
    public function warnings(): array
    {
        return $this->warnings;
    }

    /**
     * @param  array<string, mixed>  $el
     * @param  array<string, array{mode: string, value: mixed}>  $values
     * @param  list<string>  $dropBlocks
     * @param  list<array{question?: string, answer?: string}>  $faq
     * @param  array<string, string>  $staticHeadings
     * @return array<string, mixed>|null
     */
    private function transform(array $el, array $values, array $dropBlocks, array $faq, array $staticHeadings): ?array
    {
        $classes = $this->classes($el);

        // Drop a declared content-less block outright.
        if (array_intersect($classes, $dropBlocks) !== []) {
            return null;
        }

        $hook = $this->contentHook($classes);

        // FAQ: rebuild the nested-accordion from the faq list (or drop it if empty).
        if ($hook === 'wf-faq' && ($el['widgetType'] ?? null) === 'nested-accordion') {
            return $faq === [] ? null : $this->faqWidget($el, $faq);
        }

        if (($el['elType'] ?? null) === 'widget' && $hook !== null) {
            if (isset($values[$hook])) {
                return $this->setValue($el, $hook, $values[$hook]);
            }
            if (str_ends_with($hook, '-heading')) {
                // Static section title — the profile is the authority for the real
                // label (so the library's scaffolding default never ships). Unknown
                // heading → scaffolding-grade, hide it.
                if (isset($staticHeadings[$hook])) {
                    $el['settings']['title'] = $staticHeadings[$hook];

                    return $el;
                }

                return null;
            }

            return null; // unfed per-tenant content — hide (never ship a placeholder)
        }

        // Container / hook-less node: recurse, then prune if it has no widgets left.
        if (isset($el['elements']) && is_array($el['elements'])) {
            $children = [];
            foreach ($el['elements'] as $child) {
                $node = is_array($child) ? $this->transform($child, $values, $dropBlocks, $faq, $staticHeadings) : null;
                if ($node !== null) {
                    $children[] = $node;
                }
            }
            $el['elements'] = $children;

            if (($el['elType'] ?? null) === 'container' && ! $this->hasWidget($children)) {
                return null;
            }
        }

        return $el;
    }

    /**
     * @param  array<string, mixed>  $el
     * @param  array{mode: string, value: mixed}  $binding
     * @return array<string, mixed>
     */
    private function setValue(array $el, string $hook, array $binding): array
    {
        $mode = $binding['mode'];
        $type = $this->sidecar->type($hook) ?? 'text';

        $this->assertType($hook, $mode, $type);

        if ($mode === 'image') {
            $url = is_array($binding['value']) ? (string) ($binding['value']['url'] ?? '') : (string) $binding['value'];
            // External R2/CDN url → EMPTY id. `id: 0` makes Elementor resolve
            // attachment 0, fail, and render its built-in placeholder.png instead of
            // the url (a render-only leak the payload looked fine). image_size:custom
            // + dimensions stay (sibling settings → box size preserved).
            $el['settings']['image'] = ['url' => $url, 'id' => ''];

            return $el;
        }

        if ($mode === 'button') {
            $v = is_array($binding['value']) ? $binding['value'] : ['text' => (string) $binding['value']];
            $el['settings']['text'] = (string) ($v['text'] ?? '');
            if (! empty($v['url'])) {
                $el['settings']['link'] = ['url' => (string) $v['url'], 'is_external' => '', 'nofollow' => ''];
            }

            return $el;
        }

        // text → the widget's text control.
        $text = (string) $binding['value'];
        $this->warnCharRange($hook, $text);
        if (($el['widgetType'] ?? null) === 'heading') {
            $el['settings']['title'] = $text;
        } else {
            $el['settings']['editor'] = $text;
        }

        return $el;
    }

    private function assertType(string $hook, string $mode, string $sidecarType): void
    {
        $ok = match ($mode) {
            'image' => $sidecarType === 'image',
            'button' => $sidecarType === 'button',
            'text' => in_array($sidecarType, ['text', 'heading'], true),
            default => false,
        };

        if (! $ok) {
            throw new InvalidArgumentException("Hook {$hook} is type '{$sidecarType}' but was fed mode '{$mode}'.");
        }
    }

    private function warnCharRange(string $hook, string $value): void
    {
        $range = $this->sidecar->charRange($hook);
        if ($range === null) {
            return;
        }
        $len = mb_strlen(trim(strip_tags($value)));
        if ($len < $range[0] || $len > $range[1]) {
            $this->warnings[] = "{$hook}: {$len} chars outside {$range[0]}–{$range[1]}";
        }
    }

    /**
     * Rebuild the wf-faq nested-accordion from the faq list: items[] titles (through
     * plainTitle) + one index-paired, LOCKED child container per item.
     *
     * @param  array<string, mixed>  $el
     * @param  list<array{question?: string, answer?: string}>  $faq
     * @return array<string, mixed>
     */
    private function faqWidget(array $el, array $faq): array
    {
        $seed = (string) ($el['id'] ?? 'wf-faq');
        $items = [];
        $panels = [];
        $i = 0;
        foreach ($faq as $entry) {
            $q = $this->plainTitle((string) ($entry['question'] ?? ''));
            if ($q === '') {
                continue;
            }
            $items[] = ['item_title' => $q, '_id' => $this->id("{$seed}:item:{$i}")];
            $panels[] = $this->panel((string) ($entry['answer'] ?? ''), $q, "{$seed}:panel:{$i}");
            $i++;
        }

        $el['settings']['items'] = $items;
        $el['elements'] = $panels;

        return $el;
    }

    /**
     * @return array<string, mixed>
     */
    private function panel(string $answerHtml, string $title, string $seed): array
    {
        return [
            'id' => $this->id("{$seed}:c"),
            'elType' => 'container',
            'settings' => ['content_width' => 'full', '_title' => $title],
            'isInner' => true,
            'isLocked' => true,
            'elements' => [[
                'id' => $this->id("{$seed}:i"),
                'elType' => 'container',
                'settings' => ['flex_direction' => 'column'],
                'isInner' => true,
                'elements' => [[
                    'id' => $this->id("{$seed}:t"),
                    'elType' => 'widget',
                    'widgetType' => 'text-editor',
                    'settings' => ['editor' => $answerHtml],
                    'elements' => [],
                    'isInner' => false,
                ]],
            ]],
        ];
    }

    /** Strip wrapping markdown emphasis from a faq question (the #115 guard). */
    private function plainTitle(string $text): string
    {
        return trim((string) preg_replace('/^[*_]+|[*_]+$/', '', trim($text)));
    }

    /**
     * @param  array<string, mixed>  $el
     * @return list<string>
     */
    private function classes(array $el): array
    {
        $cls = $el['settings']['_css_classes'] ?? '';

        return is_string($cls) ? array_values(array_filter(explode(' ', $cls))) : [];
    }

    /**
     * The element's content hook (the wf-* class that is not the wf-block marker).
     *
     * @param  list<string>  $classes
     */
    private function contentHook(array $classes): ?string
    {
        foreach ($classes as $c) {
            if (str_starts_with($c, 'wf-') && ! str_starts_with($c, 'wf-block')) {
                return $c;
            }
        }

        return null;
    }

    /**
     * @param  list<array<string, mixed>>  $elements
     */
    private function hasWidget(array $elements): bool
    {
        foreach ($elements as $el) {
            if (($el['elType'] ?? null) === 'widget') {
                return true;
            }
            if (! empty($el['elements']) && is_array($el['elements']) && $this->hasWidget($el['elements'])) {
                return true;
            }
        }

        return false;
    }

    private function id(string $seed): string
    {
        return substr(md5($seed), 0, 7);
    }
}
