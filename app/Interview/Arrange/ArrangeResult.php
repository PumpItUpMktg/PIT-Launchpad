<?php

namespace App\Interview\Arrange;

use App\Enums\ArrangeFlagType;

/**
 * The outcome of an auto-arrange run: how many spokes each pass touched, plus the
 * flagged-for-confirm list the operator validates. Immutable; passes hand back their
 * own result and {@see merge} folds them into the run total.
 */
final class ArrangeResult
{
    /**
     * @param  array<string, int>  $applied  per-pass applied counts (e.g. ['dedup' => 2, 'nest' => 5])
     * @param  list<ArrangeFlag>  $flags
     */
    public function __construct(
        public readonly array $applied = [],
        public readonly array $flags = [],
    ) {}

    public function merge(self $other): self
    {
        $applied = $this->applied;
        foreach ($other->applied as $pass => $count) {
            $applied[$pass] = ($applied[$pass] ?? 0) + $count;
        }

        return new self($applied, [...$this->flags, ...$other->flags]);
    }

    /**
     * @return list<ArrangeFlag>
     */
    public function flagsOfType(ArrangeFlagType $type): array
    {
        return array_values(array_filter($this->flags, fn (ArrangeFlag $f) => $f->type === $type));
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'applied' => $this->applied,
            'flags' => array_map(fn (ArrangeFlag $f) => $f->toArray(), $this->flags),
        ];
    }
}
