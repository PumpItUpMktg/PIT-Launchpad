<?php

namespace App\PageBuilder\Validation;

use App\Enums\SlotContentType;
use App\PageBuilder\Entities\EntityResolver;
use App\PageBuilder\Schema\KitSchema;
use App\PageBuilder\Schema\SlotDefinition;

/**
 * The validation engine. Given a kit and a slot_payload it checks structure
 * (required slots, length bounds, cardinality, content-type shape), media
 * presence/size/alt, and entity/grounding resolution against §1 models, and
 * returns a structured result. It never throws for expected failures.
 */
class KitValidator
{
    public function __construct(private readonly EntityResolver $entities) {}

    /**
     * @param  array<string, mixed>  $payload
     */
    public function validate(KitSchema $kit, array $payload, ValidationContext $context): ValidationResult
    {
        $failures = [];

        foreach ($kit->slots as $slot) {
            if (! $slot->appliesTo($context->flags)) {
                continue;
            }

            $hasValue = array_key_exists($slot->key, $payload) && ! $this->isEmpty($payload[$slot->key]);
            $value = $payload[$slot->key] ?? null;

            $failures = [
                ...$failures,
                ...$this->validateSlotContent($slot, $value, $hasValue),
                ...$this->validateEntities($slot, $context),
            ];
        }

        return ValidationResult::fail($failures);
    }

    /**
     * @return array<int, ValidationFailure>
     */
    private function validateSlotContent(SlotDefinition $slot, mixed $value, bool $hasValue): array
    {
        // Entity-sourced slots are resolved against the database, not the
        // payload, and may legitimately be empty until publish-time assembly.
        if ($slot->source->resolvesAgainstEntities() && $slot->source->value === 'entity') {
            return $hasValue ? $this->validateShape($slot, $value) : [];
        }

        if ($slot->contentType === SlotContentType::Image
            || $slot->contentType === SlotContentType::Gallery
            || $slot->source->value === 'media') {
            return $this->validateMedia($slot, $value, $hasValue);
        }

        if (! $hasValue) {
            return $slot->isRequired()
                ? [new ValidationFailure($slot->key, ValidationCode::MissingRequiredSlot, "Required slot [{$slot->key}] is missing or empty.")]
                : [];
        }

        return $this->validateShape($slot, $value);
    }

    /**
     * @return array<int, ValidationFailure>
     */
    private function validateShape(SlotDefinition $slot, mixed $value): array
    {
        if ($slot->isRepeater()) {
            return $this->validateRepeater($slot, $value);
        }

        if ($slot->contentType->isText()) {
            return $this->validateText($slot, $value);
        }

        if (! $this->matchesObjectShape($slot->contentType, $value)) {
            return [new ValidationFailure($slot->key, ValidationCode::ContentTypeMismatch, "Slot [{$slot->key}] does not match content type [{$slot->contentType->value}].")];
        }

        return [];
    }

    /**
     * @return array<int, ValidationFailure>
     */
    private function validateRepeater(SlotDefinition $slot, mixed $value): array
    {
        if (! is_array($value) || ! array_is_list($value)) {
            return [new ValidationFailure($slot->key, ValidationCode::ContentTypeMismatch, "Repeater slot [{$slot->key}] expects a list of items.")];
        }

        $failures = [];
        $count = count($value);
        $min = $slot->cardinality->min;
        $max = $slot->cardinality->max;

        if ($min !== null && $count < $min) {
            $failures[] = new ValidationFailure($slot->key, ValidationCode::CardinalityBelowMinimum, "Slot [{$slot->key}] has {$count} items, minimum {$min}.");
        }
        if ($max !== null && $count > $max) {
            $failures[] = new ValidationFailure($slot->key, ValidationCode::CardinalityAboveMaximum, "Slot [{$slot->key}] has {$count} items, maximum {$max}.");
        }

        foreach ($value as $item) {
            if (! $this->matchesElementShape($slot->contentType, $item)) {
                $failures[] = new ValidationFailure($slot->key, ValidationCode::ContentTypeMismatch, "An item in slot [{$slot->key}] does not match content type [{$slot->contentType->value}].");
                break;
            }
        }

        return $failures;
    }

    /**
     * @return array<int, ValidationFailure>
     */
    private function validateText(SlotDefinition $slot, mixed $value): array
    {
        if (! is_string($value)) {
            return [new ValidationFailure($slot->key, ValidationCode::ContentTypeMismatch, "Slot [{$slot->key}] expects text.")];
        }

        $failures = [];
        $length = mb_strlen(trim($value));
        $min = $slot->constraints->minLength;
        $max = $slot->constraints->maxLength;

        if ($min !== null && $length < $min) {
            $failures[] = new ValidationFailure($slot->key, ValidationCode::LengthBelowMinimum, "Slot [{$slot->key}] is {$length} chars, minimum {$min}.");
        }
        if ($max !== null && $length > $max) {
            $failures[] = new ValidationFailure($slot->key, ValidationCode::LengthAboveMaximum, "Slot [{$slot->key}] is {$length} chars, maximum {$max}.");
        }

        return $failures;
    }

    /**
     * @return array<int, ValidationFailure>
     */
    private function validateMedia(SlotDefinition $slot, mixed $value, bool $hasValue): array
    {
        if (! $hasValue) {
            return $slot->isRequired()
                ? [new ValidationFailure($slot->key, ValidationCode::MediaMissing, "Media slot [{$slot->key}] is missing.")]
                : [];
        }

        $items = $slot->isRepeater() && is_array($value) && array_is_list($value) ? $value : [$value];
        $failures = [];
        $media = $slot->constraints->media;

        foreach ($items as $item) {
            if (! is_array($item) || (! isset($item['src']) && ! isset($item['r2_key']) && ! isset($item['url']))) {
                $failures[] = new ValidationFailure($slot->key, ValidationCode::MediaMissing, "Media slot [{$slot->key}] has no source reference.");

                continue;
            }

            if ($media !== null && $media->altRequired && empty($item['alt'])) {
                $failures[] = new ValidationFailure($slot->key, ValidationCode::MediaAltMissing, "Media slot [{$slot->key}] is missing alt text.");
            }

            if ($media !== null && ($media->minWidth !== null || $media->minHeight !== null)) {
                $width = (int) ($item['width'] ?? 0);
                $height = (int) ($item['height'] ?? 0);
                if ($width < ($media->minWidth ?? 0) || $height < ($media->minHeight ?? 0)) {
                    $failures[] = new ValidationFailure($slot->key, ValidationCode::MediaSizeBelowMinimum, "Media slot [{$slot->key}] is below the declared minimum size.");
                }
            }
        }

        return $failures;
    }

    /**
     * @return array<int, ValidationFailure>
     */
    private function validateEntities(SlotDefinition $slot, ValidationContext $context): array
    {
        $entity = $slot->constraints->entity;

        if (! $slot->source->resolvesAgainstEntities() || $entity === null) {
            return [];
        }

        $count = $this->entities->count($entity, $context);

        if ($count === null) {
            return [new ValidationFailure($slot->key, ValidationCode::EntityUnresolved, "Slot [{$slot->key}] references unknown entity set [{$entity}].")];
        }

        $min = $slot->constraints->minEntities;

        if ($min !== null && $count < $min) {
            return [new ValidationFailure($slot->key, ValidationCode::EntityBelowMinimum, "Slot [{$slot->key}] resolved {$count} of minimum {$min} from [{$entity}].")];
        }

        return [];
    }

    private function isEmpty(mixed $value): bool
    {
        if (is_string($value)) {
            return trim($value) === '';
        }

        if (is_array($value)) {
            return $value === [];
        }

        return $value === null;
    }

    private function matchesObjectShape(SlotContentType $type, mixed $value): bool
    {
        if (! is_array($value)) {
            return false;
        }

        return match ($type) {
            // A CTA needs a label; its destination (url/action) is resolved from §1
            // conversion data at publish for entity-sourced CTAs (the model writes
            // the label, never invents a URL), so url/action are optional at draft.
            SlotContentType::Cta => isset($value['label']) && $value['label'] !== '',
            SlotContentType::Stat => isset($value['value']) && isset($value['label']),
            SlotContentType::Testimonial => (isset($value['quote']) || isset($value['body'])) && isset($value['author']),
            SlotContentType::Map => (isset($value['lat']) && isset($value['lng'])) || isset($value['location_id']),
            default => true,
        };
    }

    private function matchesElementShape(SlotContentType $type, mixed $item): bool
    {
        return match ($type) {
            SlotContentType::List => is_string($item),
            SlotContentType::Faq => is_array($item) && isset($item['question'], $item['answer']),
            SlotContentType::Gallery => is_array($item) && (isset($item['src']) || isset($item['r2_key']) || isset($item['url'])),
            default => $this->matchesObjectShape($type, $item),
        };
    }
}
