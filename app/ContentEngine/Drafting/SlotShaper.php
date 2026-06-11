<?php

namespace App\ContentEngine\Drafting;

use App\Enums\SlotContentType;
use App\PageBuilder\Schema\SlotDefinition;

/**
 * The kit-aware half of sentinel parsing for PAGES: it takes the format-only raw
 * slot map ({@see SentinelParser} — a scalar or a list of raw strings per key) and
 * shapes each value to what {@see \App\PageBuilder\Validation\KitValidator} expects,
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
            $items = is_array($value) ? array_values($value) : [$value];

            return array_map(fn ($item) => $this->shapeItem($slot->contentType, $item), $items);
        }

        if ($slot->contentType->isText()) {
            return is_array($value) ? (string) ($value[0] ?? '') : (string) $value;
        }

        return $this->shapeItem($slot->contentType, is_array($value) ? ($value[0] ?? '') : $value);
    }

    private function shapeItem(SlotContentType $type, mixed $item): mixed
    {
        if (is_array($item)) {
            return $item; // already structured (defensive — e.g. a re-shaped value).
        }

        $raw = (string) $item;
        $fields = array_map('trim', explode(Sentinel::FIELD, $raw));

        return match ($type) {
            SlotContentType::Faq => ['question' => $fields[0] ?? '', 'answer' => $fields[1] ?? ''],
            SlotContentType::Stat => ['value' => $fields[0] ?? '', 'label' => $fields[1] ?? ''],
            SlotContentType::Testimonial => ['quote' => $fields[0] ?? '', 'author' => $fields[1] ?? ''],
            SlotContentType::Cta => array_filter([
                'label' => $fields[0] ?? '',
                'url' => $fields[1] ?? null,
                'action' => $fields[2] ?? null,
            ], static fn ($v) => $v !== null && $v !== ''),
            default => $raw,
        };
    }
}
