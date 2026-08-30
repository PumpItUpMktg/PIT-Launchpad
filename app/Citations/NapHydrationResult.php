<?php

namespace App\Citations;

/**
 * The outcome of a {@see NapProfileHydrator} pass — deliberately non-destructive, so the surfaces can report
 * exactly what changed. `created` = a fresh NAP built from the GBP data; `updated` = an existing NAP had blank
 * fields filled (never overwritten); `skipped` = no NAP existed and Google was missing a required field (the
 * NOT-NULL columns); `noop` = an existing NAP was already complete, nothing to fill.
 */
final class NapHydrationResult
{
    /**
     * @param  list<string>  $fields  the NAP fields written (created) or filled (updated)
     * @param  list<string>  $missing  required fields Google could not supply (skipped)
     */
    private function __construct(
        public readonly string $outcome,
        public readonly array $fields = [],
        public readonly array $missing = [],
    ) {}

    public function created(): bool
    {
        return $this->outcome === 'created';
    }

    public function updated(): bool
    {
        return $this->outcome === 'updated';
    }

    public function skipped(): bool
    {
        return $this->outcome === 'skipped';
    }

    /** Created or filled — i.e. the NAP now carries data it did not before. */
    public function changed(): bool
    {
        return $this->outcome === 'created' || $this->outcome === 'updated';
    }

    /** @param list<string> $fields */
    public static function createdWith(array $fields): self
    {
        return new self('created', fields: $fields);
    }

    /** @param list<string> $fields */
    public static function updatedWith(array $fields): self
    {
        return new self('updated', fields: $fields);
    }

    /** @param list<string> $missing */
    public static function skippedMissing(array $missing): self
    {
        return new self('skipped', missing: $missing);
    }

    public static function noop(): self
    {
        return new self('noop');
    }
}
