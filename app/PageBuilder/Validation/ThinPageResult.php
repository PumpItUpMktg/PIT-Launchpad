<?php

namespace App\PageBuilder\Validation;

/**
 * Outcome of the thin-page guard: whether the page has earned publication via
 * entity-backed proof, and how much entity proof resolved.
 */
final class ThinPageResult
{
    public function __construct(
        public readonly bool $earned,
        public readonly int $proofEntityCount,
    ) {}
}
