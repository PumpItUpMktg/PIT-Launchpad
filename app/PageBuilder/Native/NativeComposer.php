<?php

namespace App\PageBuilder\Native;

use App\Enums\SlotContentType;
use App\Enums\SlotRole;
use App\PageBuilder\Schema\KitSchema;
use App\PageBuilder\Schema\SlotDefinition;

/**
 * Composes a page body as a NATIVE Elementor (container-based) document — the
 * Tier-1 native-widget direction. Each slot becomes the native widget that matches
 * the wireframe library and is editable in Elementor, instead of slot content
 * dumped into `text-editor` widgets styled by plugin CSS. Variable-N content (FAQ,
 * features) is expanded per-page here, where the resolved content is known.
 *
 * The output is the `_elementor_data` element tree — a list of top-level zone
 * CONTAINERS (flexbox), never legacy section/column (the verified export is all
 * containers; nested-accordion requires it). Slots group into the same ordered
 * zones as #109 (hero / explainer / features / proof / faq / cta); zone width is a
 * CSS concern (the `lp-zone--<name>` class), kept out of guessed JSON.
 *
 * Every widget shape is templated off a REAL Elementor 4.1.3 export, never authored
 * from memory (the #110 lesson): heading=`title`, text-editor=`editor`,
 * image=`image`, icon-list=`icon_list[{text,_id}]`, button=`text`(+`link`),
 * nested-accordion=`items[{item_title,_id}]` + locked child containers paired by
 * index. Slots without a verified native widget yet (stat / testimonial / map / nap)
 * are skipped here and follow in Tier-2.
 */
final class NativeComposer
{
    /**
     * The full native body for a page: ordered zone containers of native widgets,
     * routed from the kit's slot definitions and the resolved slot values.
     *
     * @param  array<string, mixed>  $values  slot_payload keyed by slot key
     * @param  array<string, array<string, mixed>>  $images  image map keyed by slot key
     * @return list<array<string, mixed>>
     */
    public function compose(KitSchema $kit, array $values, array $images = [], string $seed = 'page'): array
    {
        $zones = [];
        foreach ($kit->slots as $slot) {
            $widget = $this->widgetFor($slot, $values[$slot->key] ?? null, $images, $seed);
            if ($widget !== null) {
                $zones[$this->zoneFor($slot)][] = $widget;
            }
        }

        $document = [];
        foreach ($zones as $name => $widgets) {
            $document[] = $this->zone($name, $widgets, $seed);
        }

        return $document;
    }

    /**
     * The native `_elementor_data` tree for a page's FAQ alone (the single-slot
     * proof / re-usable). Returns [] when there is no faq.
     *
     * @param  list<array{question?: string, answer?: string}>  $faq
     * @return list<array<string, mixed>>
     */
    public function faqDocument(array $faq, string $seed = 'faq'): array
    {
        $accordion = $this->faqAccordion($faq, $seed);

        return $accordion === null ? [] : [$this->zone('faq', [$accordion], $seed)];
    }

    /**
     * Route a slot to its native widget (baking the resolved value), or null when
     * its content is empty or it has no Tier-1 native widget yet.
     *
     * @param  array<string, array<string, mixed>>  $images
     * @return array<string, mixed>|null
     */
    private function widgetFor(SlotDefinition $slot, mixed $value, array $images, string $seed): ?array
    {
        $s = "{$seed}:{$slot->key}";

        return match ($slot->contentType) {
            SlotContentType::Heading => $this->heading($this->str($value), $slot->role === SlotRole::HeroProblem ? 'h1' : 'h2', $s),
            SlotContentType::ShortText => $slot->role === SlotRole::HeroSolution
                ? $this->heading($this->str($value), 'h2', $s)
                : $this->textEditor($this->str($value), $s),
            SlotContentType::LongText, SlotContentType::RichText => $this->textEditor($this->str($value), $s),
            SlotContentType::List => $this->iconList($this->strings($value), $s),
            SlotContentType::Faq => $this->faqAccordion($this->items($value), $s),
            SlotContentType::Image => $this->image($this->image_url($slot->key, $value, $images), $s),
            SlotContentType::Cta => $this->button($value, $s),
            default => null, // stat / testimonial / map / gallery → Tier-2
        };
    }

    /** @return array<string, mixed>|null */
    private function heading(string $title, string $size, string $seed): ?array
    {
        if (trim($title) === '') {
            return null;
        }
        $settings = ['title' => $title];
        if ($size !== 'h2') {
            $settings['header_size'] = $size;
        }

        return $this->widget('heading', $settings, $seed);
    }

    /** @return array<string, mixed>|null */
    private function textEditor(string $html, string $seed): ?array
    {
        return trim($html) === '' ? null : $this->widget('text-editor', ['editor' => $html], $seed);
    }

    /**
     * @param  list<string>  $items
     * @return array<string, mixed>|null
     */
    private function iconList(array $items, string $seed): ?array
    {
        $rows = [];
        foreach ($items as $i => $text) {
            if (trim($text) === '') {
                continue;
            }
            $rows[] = ['text' => $text, '_id' => $this->shortId("{$seed}:row:{$i}")];
        }

        return $rows === [] ? null : $this->widget('icon-list', ['icon_list' => $rows], $seed);
    }

    /** @return array<string, mixed>|null */
    private function image(string $url, string $seed): ?array
    {
        return $url === '' ? null : $this->widget('image', ['image' => ['url' => $url, 'id' => 0]], $seed);
    }

    /**
     * The CTA as a native Button — the always-present call action from a conversion
     * block (label + tel: link). The embedded GHL form is Tier-2.
     *
     * @return array<string, mixed>|null
     */
    private function button(mixed $value, string $seed): ?array
    {
        if (! is_array($value)) {
            return null;
        }

        $tel = trim((string) ($value['tel'] ?? ''));
        $label = trim((string) ($value['call_label'] ?? $value['label'] ?? 'Call Now'));
        $phone = trim((string) ($value['phone'] ?? ''));
        $url = $tel !== '' ? $tel : trim((string) ($value['url'] ?? ''));

        if ($url === '' || $label === '') {
            return null;
        }

        $settings = ['text' => $phone !== '' ? "{$label} {$phone}" : $label];
        $settings['link'] = ['url' => $url, 'is_external' => '', 'nofollow' => ''];

        return $this->widget('button', $settings, $seed);
    }

    /**
     * A nested-accordion from the faq slot — verified shape: titles in
     * `settings.items[]`; each answer a LOCKED child container (→ inner column
     * container → text-editor), paired to its title BY INDEX.
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
            if ($question === '') {
                continue;
            }

            $items[] = ['item_title' => $question, '_id' => $this->shortId("{$seed}:item:{$i}")];
            $panels[] = $this->panel((string) ($entry['answer'] ?? ''), $question, "{$seed}:panel:{$i}");
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
     * A leaf native widget in the verified export shape.
     *
     * @param  array<string, mixed>  $settings
     * @return array<string, mixed>
     */
    private function widget(string $type, array $settings, string $seed): array
    {
        return [
            'id' => $this->id("{$seed}:{$type}"),
            'elType' => 'widget',
            'widgetType' => $type,
            'settings' => $settings,
            'elements' => [],
            'isInner' => false,
        ];
    }

    /**
     * A zone as a flex container (the container-based port of a #109 section). Width
     * is the `lp-zone lp-zone--<name>` class + plugin CSS (the 0.7.0 pattern) — no
     * width key emitted (the verified export carries none).
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

    /** Map a slot to its design zone (the #109 grouping, by role + repeater-ness). */
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

    private function str(mixed $value): string
    {
        return is_array($value) ? (string) ($value[0] ?? '') : (string) $value;
    }

    /**
     * @return list<string>
     */
    private function strings(mixed $value): array
    {
        if (! is_array($value)) {
            $value = $value === null ? [] : [$value];
        }

        return array_values(array_map(fn ($v) => is_string($v) ? $v : (string) $v, $value));
    }

    /**
     * @return list<array{question?: string, answer?: string}>
     */
    private function items(mixed $value): array
    {
        if (! is_array($value)) {
            return [];
        }

        return array_values(array_filter($value, 'is_array'));
    }

    /**
     * @param  array<string, array<string, mixed>>  $images
     */
    private function image_url(string $key, mixed $value, array $images): string
    {
        if (isset($images[$key]['url'])) {
            return (string) $images[$key]['url'];
        }
        if (is_array($value) && isset($value['url'])) {
            return (string) $value['url'];
        }

        return '';
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
