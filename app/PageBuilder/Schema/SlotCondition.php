<?php

namespace App\PageBuilder\Schema;

/**
 * A conditional render/requirement gate for a slot — e.g. nap_block and map
 * only apply when is_storefront is true. Evaluated against context flags.
 */
final class SlotCondition
{
    public function __construct(
        public readonly string $field,
        public readonly mixed $equals,
    ) {}

    /**
     * @param  array<string, mixed>  $flags
     */
    public function matches(array $flags): bool
    {
        return ($flags[$this->field] ?? null) === $this->equals;
    }

    /**
     * @param  array<string, mixed>  $data
     */
    public static function fromArray(array $data): self
    {
        return new self(
            field: (string) $data['field'],
            equals: $data['equals'] ?? true,
        );
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return ['field' => $this->field, 'equals' => $this->equals];
    }
}
