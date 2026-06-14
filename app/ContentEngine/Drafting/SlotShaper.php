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
            // List items are prose too — inline-Markdown each one so the generator's
            // **bold** / [links] become real HTML, exactly like a text slot (a flat
            // <li> showing literal **Step 1** was the gap).
            SlotContentType::List => trim(Str::inlineMarkdown($raw, ['html_input' => 'allow'])),
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
     * A faq item as {question, answer}. The intended form is `question || answer`,
     * but the model recurrently emits a single labeled block in several shapes:
     * inline labels ("Question: … / Answer: …"), bare label LINES ("question" on its
     * own line, then the text, then "answer", then the text), or just a question line
     * followed by the answer ("Q\nA"). All must split into q/a — collapsing the label
     * word "question" into the title broke the accordion AND the FAQPage schema
     * (both read this slot), the native-cutover regression.
     *
     * @param  list<string>  $fields  the raw split on the `||` delimiter
     * @return array{question: string, answer: string}
     */
    private function faqItem(string $raw, array $fields): array
    {
        if (count($fields) >= 2 && trim($fields[1]) !== '') {
            return ['question' => trim($fields[0]), 'answer' => $this->answerHtml($fields[1])];
        }

        // Line-based, label-tolerant parse for every non-delimited shape.
        $questionParts = [];
        $answerParts = [];
        $mode = 'question';
        $sawAnswerMarker = false;

        foreach (preg_split('/\r\n|\r|\n/', trim($raw)) ?: [] as $line) {
            $line = trim($line);
            if ($line === '') {
                continue;
            }

            // A line that is ONLY a "question"/"q" or "answer"/"a" label switches the
            // bucket and is dropped (the model emits the field names on their own lines).
            if (preg_match('/^q(?:uestion)?\s*[:.)\-]?$/i', $line)) {
                $mode = 'question';

                continue;
            }
            if (preg_match('/^a(?:nswer)?\s*[:.)\-]?$/i', $line)) {
                $mode = 'answer';
                $sawAnswerMarker = true;

                continue;
            }

            // An inline "Question: …" / "Answer: …" label sets the bucket + keeps the text.
            if (preg_match('/^q(?:uestion)?\s*[:.)\-]\s*(.+)$/i', $line, $m)) {
                $mode = 'question';
                $line = trim($m[1]);
            } elseif (preg_match('/^a(?:nswer)?\s*[:.)\-]\s*(.+)$/i', $line, $m)) {
                $mode = 'answer';
                $sawAnswerMarker = true;
                $line = trim($m[1]);
            }

            $mode === 'answer' ? $answerParts[] = $line : $questionParts[] = $line;
        }

        // No explicit answer marker but multiple question lines → first line is the
        // question, the rest the answer (the unlabeled "Q\nA" shape).
        if (! $sawAnswerMarker && $answerParts === [] && count($questionParts) > 1) {
            $answerParts = array_slice($questionParts, 1);
            $questionParts = array_slice($questionParts, 0, 1);
        }

        return [
            'question' => trim(implode(' ', $questionParts)),
            'answer' => $this->answerHtml(implode("\n", $answerParts)),
        ];
    }

    /**
     * An FAQ answer is prose — inline-Markdown it (like a short-text slot) so **bold**
     * / links render, not literal asterisks. The question stays plain (a label).
     */
    private function answerHtml(string $answer): string
    {
        $answer = trim($answer);

        return $answer === '' ? '' : trim(Str::inlineMarkdown($answer, ['html_input' => 'allow']));
    }
}
