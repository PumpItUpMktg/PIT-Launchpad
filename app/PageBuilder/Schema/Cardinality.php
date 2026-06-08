<?php

namespace App\PageBuilder\Schema;

/**
 * Whether a slot holds a single value or a repeater of min..max items.
 */
final class Cardinality
{
    public function __construct(
        public readonly string $type = 'single',
        public readonly ?int $min = null,
        public readonly ?int $max = null,
    ) {}

    public static function single(): self
    {
        return new self('single');
    }

    public static function repeater(?int $min, ?int $max): self
    {
        return new self('repeater', $min, $max);
    }

    public function isRepeater(): bool
    {
        return $this->type === 'repeater';
    }

    /**
     * @param  array<string, mixed>  $data
     */
    public static function fromArray(array $data): self
    {
        return new self(
            type: (string) ($data['type'] ?? 'single'),
            min: isset($data['min']) ? (int) $data['min'] : null,
            max: isset($data['max']) ? (int) $data['max'] : null,
        );
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        $out = ['type' => $this->type];

        if ($this->isRepeater()) {
            $out['min'] = $this->min;
            $out['max'] = $this->max;
        }

        return $out;
    }
}
