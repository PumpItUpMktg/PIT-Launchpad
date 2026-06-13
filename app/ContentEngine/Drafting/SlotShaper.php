<?php

namespace App\ContentEngine\Drafting;

use App\Enums\SlotContentType;
use App\PageBuilder\Schema\SlotDefinition;
use App\PageBuilder\Validation\KitValidator;
use Illuminate\Support\Str;

/**
 * The kit-aware half of sentinel parsing for PAGES: it takes the format-only raw
 * slot map ({@see SentinelParser} — a scalar or a list of raw strings per key) and
 * shapes each value to what {@see KitValidator} expects,
 * using the slot's content_type and cardinality. Off-schema keys are dropped (the
 * slot key is the render contract). Object content carries its sub-fields after the
 * `||` delimiter in the order the prompt declares, and is re-keyed here:
 *
 *   - faq         → {question, answer}
 *   - stat        → {value, label}
 *   - testimonial → {quote, author}
 *   - cta         → {label, url?, action?}
 *
 * Repeater slots always become a list (a lone item is wrapped) so cardinality is
 * judged correctly; `list` items stay plain strings.
 */
final class SlotShaper
{
    /**
     * @param  list<SlotDefinition>  $definitions
     * @param  array<string, mixed>  $raw
     * @return array<string, mixed>
     */
    public function shape(array $definitions, array $raw): array
    {
        $byKey = [];
        foreach ($definitions as $definition) {
            $byKey[$definition->key] = $definition;
        }

        $shaped = [];
        foreach ($raw as $key => $value) {
            $definition = $byKey[$key] ?? null;
            if ($definition === null) {
                continue; // off-schema key → drop (renders as a blank section).
            }

            $shaped[$key] = $this->shapeValue($definition, $value);
        }

        return $shaped;
    }

    private function shapeValue(SlotDefinition $slot, mixed $value): mixed
    {
        if ($slot->isRepeater()) {
            $items = $this->repeaterItems($slot->contentType, $value);

            return array_map(fn ($item) => $this->shapeItem($slot->contentType, $item), $items);
        }

        if ($slot->contentType->isText()) {
            return $this->renderText($slot->contentType, is_array($value) ? (string) ($value[0] ?? '') : (string) $value);
        }

        return $this->shapeItem($slot->contentType, is_array($value) ? ($value[0] ?? '') : $value);
    }

    /**
     * Repeater items as a list. A model often returns a plain LIST as one bulleted
     * block (a single string) instead of separate items — split it so each line is
     * an item; other repeater types (faq/stat/…) keep a lone item as-is.
     *
     * @return list<mixed>
     */
    private function repeaterItems(SlotContentType $type, mixed $value): array
    {
        if (is_array($value)) {
            return array_values($value);
        }

        if ($type === SlotContentType::List && is_string($value) && $this->isMultiline($value)) {
            return $this->splitBullets($value);
        }

        return [$value];
    }

    private function isMultiline(string $value): bool
    {
        return (bool) preg_match('/\r|\n/', $value);
    }

    /**
     * @return list<string>
     */
    private function splitBullets(string $value): array
    {
        $items = [];
        foreach (preg_split('/\r\n|\r|\n/', $value) ?: [] as $line) {
            // Strip a leading bullet/number marker (-, –, —, *, •, ·, "1.", "2)").
            $line = trim((string) preg_replace('/^\s*(?:[-–—*•·]|\d+[.)])\s+/u', '', $line));
            if ($line !== '') {
                $items[] = $line;
            }
        }

        return $items === [] ? [$value] : $items;
    }

    /**
     * Render a text slot's value: headings stay plain; short text gets inline
     * Markdown (no wrapping block); long/rich text gets block Markdown — so the
     * generator's **bold**, – bullets, etc. become real HTML, never literal text.
     * Existing HTML passes through (html_input=allow), so it's idempotent enough
     * for a re-draft.
     */
    private function renderText(SlotContentType $type, string $text): string
    {
        $text = trim($text);
        if ($text === '') {
            return '';
        }

        return match ($type) {
            SlotContentType::Heading => $text,
            SlotContentType::ShortText => trim(Str::inlineMarkdown($text, ['html_input' => 'allow'])),
            default => trim(Str::markdown($text, ['html_input' => 'allow'])),
        };
    }

    private function shapeItem(SlotContentType $type, mixed $item): mixed
    {
        if (is_array($item)) {
            return $item; // already structured (defensive — e.g. a re-shaped value).
        }

        $raw = (string) $item;
        $fields = array_map('trim', explode(Sentinel::FIELD, $raw));

        return match ($type) {
            SlotContentType::Faq => $this->faqItem($raw, $fields),
            SlotContentType::Stat => ['value' => $fields[0], 'label' => $fields[1] ?? ''],
            SlotContentType::Testimonial => ['quote' => $fields[0], 'author' => $fields[1] ?? ''],
            SlotContentType::Cta => array_filter([
                'label' => $fields[0],
                'url' => $fields[1] ?? null,
                'action' => $fields[2] ?? null,
            ], static fn ($v) => $v !== null && $v !== ''),
            default => $raw,
        };
    }

    /**
     * A faq item as {question, answer}. The intended form is `question || answer`;
     * but the model recurrently emits a single labeled block ("Question: …\nAnswer:
     * …" or just "Q\nA"). Honor the delimiter first, then fall back to a tolerant
     * label/line split so the q/a never collapse into one field (which broke both
     * the rendered FAQ and the FAQPage schema).
     *
     * @param  list<string>  $fields  the raw split on the `||` delimiter
     * @return array{question: string, answer: string}
     */
    private function faqItem(string $raw, array $fields): array
    {
        if (count($fields) >= 2 && trim($fields[1]) !== '') {
            return ['question' => trim($fields[0]), 'answer' => trim($fields[1])];
        }

        // Drop a leading "Question:" / "Q:" label, then split on an "Answer:" label
        // or the first line break.
        $text = (string) preg_replace('/^\s*q(?:uestion)?\s*[:.)\-]\s*/i', '', trim($raw), 1);

        if (preg_match('/^(.+?)(?:\s*a(?:nswer)?\s*[:.)\-]\s*|\s*\n+\s*)(.+)$/is', $text, $m)) {
            $answer = (string) preg_replace('/^\s*a(?:nswer)?\s*[:.)\-]\s*/i', '', trim($m[2]), 1);

            return ['question' => trim($m[1]), 'answer' => trim($answer)];
        }

        return ['question' => $text, 'answer' => ''];
    }
}
