<?php

namespace App\PageBuilder\Validation;

/**
 * The structured outcome of validating a payload against a kit: pass/fail plus
 * the list of failures. Expected failures are values here, never exceptions.
 */
final class ValidationResult
{
    /**
     * @param  array<int, ValidationFailure>  $failures
     */
    public function __construct(
        public readonly array $failures = [],
    ) {}

    public static function pass(): self
    {
        return new self([]);
    }

    /**
     * @param  array<int, ValidationFailure>  $failures
     */
    public static function fail(array $failures): self
    {
        return new self(array_values($failures));
    }

    public function passed(): bool
    {
        return $this->failures === [];
    }

    public function failed(): bool
    {
        return ! $this->passed();
    }

    /**
     * @return array<int, ValidationFailure>
     */
    public function failuresFor(string $slot): array
    {
        return array_values(array_filter($this->failures, fn (ValidationFailure $f) => $f->slot === $slot));
    }

    public function hasCode(ValidationCode $code): bool
    {
        foreach ($this->failures as $failure) {
            if ($failure->code === $code) {
                return true;
            }
        }

        return false;
    }

    /**
     * @return array<int, string>
     */
    public function codes(): array
    {
        return array_values(array_unique(array_map(fn (ValidationFailure $f) => $f->code->value, $this->failures)));
    }

    public function merge(self $other): self
    {
        return new self([...$this->failures, ...$other->failures]);
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    public function toArray(): array
    {
        return array_map(fn (ValidationFailure $f) => $f->toArray(), $this->failures);
    }
}
