<?php

namespace App\PageBuilder\Native;

/**
 * Composes a page body as a NATIVE Elementor (container-based) document — the
 * Tier-1 native-widget direction. Instead of dumping slot content into
 * `text-editor` widgets styled by plugin CSS, each slot becomes the native widget
 * that matches the wireframe library and is editable in Elementor. Variable-N
 * content (FAQ, features, …) is expanded per-page here, where the resolved content
 * is known — a shared dynamic template can't do that.
 *
 * The output is the `_elementor_data` element tree (a list of top-level
 * containers). The body is ALL containers (flexbox), never legacy section/column —
 * the verified export is container-based and nested-accordion requires it. The
 * #109 zone treatment (width / order / rhythm) ports onto container settings.
 *
 * Shapes are templated off REAL exports from the target Elementor (4.1.3), never
 * authored from memory — the FAQ accordion below matches the verified
 * `nested-accordion` export: titles in `settings.items[]`, content as one locked
 * child container per item, paired BY INDEX.
 *
 * This first cut is FAQ-only (the single-slot proof). CTA/Button, Icon List, and
 * card grids land next, each off its own verified export.
 */
final class NativeComposer
{
    /**
     * The native `_elementor_data` tree for a page's FAQ, as a single zone
     * container holding a nested Accordion. Returns [] when there is no faq.
     *
     * @param  list<array{question?: string, answer?: string}>  $faq
     * @return list<array<string, mixed>>
     */
    public function faqDocument(array $faq, string $seed = 'faq'): array
    {
        $accordion = $this->faqAccordion($faq, $seed);
        if ($accordion === null) {
            return [];
        }

        return [$this->zone('faq', [$accordion], $seed)];
    }

    /**
     * A nested-accordion widget from the faq slot. Titles go in `settings.items[]`
     * ({item_title,_id}); each answer becomes one locked child container in
     * `elements[]`, in the SAME order — Elementor pairs panel↔title by index. Prose
     * answers stay in a `text-editor` (correct tool); they already carry shaped
     * inline-HTML from the slot.
     *
     * @param  list<array{question?: string, answer?: string}>  $faq
     * @return array<string, mixed>|null
     */
    public function faqAccordion(array $faq, string $seed = 'faq'): ?array
    {
        $items = [];
        $panels = [];

        $i = 0;
        foreach ($faq as $entry) {
            $question = trim((string) ($entry['question'] ?? ''));
            $answer = (string) ($entry['answer'] ?? '');
            if ($question === '') {
                continue;
            }

            $items[] = [
                'item_title' => $question,
                '_id' => $this->shortId("{$seed}:item:{$i}"),
            ];
            $panels[] = $this->panel($answer, $question, "{$seed}:panel:{$i}");
            $i++;
        }

        if ($items === []) {
            return null;
        }

        return [
            'id' => $this->id("{$seed}:accordion"),
            'elType' => 'widget',
            'widgetType' => 'nested-accordion',
            'settings' => ['items' => $items],
            'elements' => $panels,
            'isInner' => false,
        ];
    }

    /**
     * One nested-accordion content panel, mirroring the verified export flags: a
     * LOCKED inner child container (`content_width:full`, `_title`) whose own inner
     * column container (`flex_direction:column`) holds the answer as a text-editor.
     * `isLocked` + `isInner` match how Elementor manages accordion item panels.
     *
     * @return array<string, mixed>
     */
    private function panel(string $answerHtml, string $title, string $seed): array
    {
        $textEditor = [
            'id' => $this->id("{$seed}:text"),
            'elType' => 'widget',
            'widgetType' => 'text-editor',
            'settings' => ['editor' => $answerHtml],
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

    /**
     * A zone as a flex container (the container-based port of a #109 section). Width
     * is left to the `lp-zone lp-zone--<name>` class + plugin CSS (the 0.7.0 pattern)
     * — no boxed-width emitted into JSON (unverified container key, kept out).
     *
     * @param  list<array<string, mixed>>  $children
     * @return array<string, mixed>
     */
    private function zone(string $name, array $children, string $seed): array
    {
        return [
            'id' => $this->id("{$seed}:zone:{$name}"),
            'elType' => 'container',
            'settings' => ['_css_classes' => "lp-zone lp-zone--{$name}"],
            'elements' => $children,
            'isInner' => false,
        ];
    }

    /** Deterministic 7-char Elementor element id (unique per distinct seed). */
    private function id(string $seed): string
    {
        return substr(md5($seed), 0, 7);
    }

    /** Repeater-item id (Elementor uses a short ~7-char id for `items[]._id`). */
    private function shortId(string $seed): string
    {
        return substr(md5("item:{$seed}"), 0, 7);
    }
}
