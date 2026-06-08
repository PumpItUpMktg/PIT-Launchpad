<?php

namespace App\PageBuilder\Schema;

/**
 * Validation constraints for a slot: presence, text length bounds, format, the
 * entity set a grounded/entity slot resolves against (+ its minimum count), and
 * media constraints for image/gallery/map slots.
 */
final class SlotConstraints
{
    public function __construct(
        public readonly bool $required = false,
        public readonly ?int $minLength = null,
        public readonly ?int $maxLength = null,
        public readonly ?string $format = null,
        public readonly ?string $entity = null,
        public readonly ?int $minEntities = null,
        public readonly ?MediaConstraints $media = null,
    ) {}

    /**
     * @param  array<string, mixed>  $data
     */
    public static function fromArray(array $data): self
    {
        return new self(
            required: (bool) ($data['required'] ?? false),
            minLength: isset($data['min_length']) ? (int) $data['min_length'] : null,
            maxLength: isset($data['max_length']) ? (int) $data['max_length'] : null,
            format: isset($data['format']) ? (string) $data['format'] : null,
            entity: isset($data['entity']) ? (string) $data['entity'] : null,
            minEntities: isset($data['min_entities']) ? (int) $data['min_entities'] : null,
            media: isset($data['media']) && is_array($data['media'])
                ? MediaConstraints::fromArray($data['media'])
                : null,
        );
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        $out = ['required' => $this->required];

        if ($this->minLength !== null) {
            $out['min_length'] = $this->minLength;
        }
        if ($this->maxLength !== null) {
            $out['max_length'] = $this->maxLength;
        }
        if ($this->format !== null) {
            $out['format'] = $this->format;
        }
        if ($this->entity !== null) {
            $out['entity'] = $this->entity;
        }
        if ($this->minEntities !== null) {
            $out['min_entities'] = $this->minEntities;
        }
        if ($this->media !== null) {
            $out['media'] = $this->media->toArray();
        }

        return $out;
    }
}
