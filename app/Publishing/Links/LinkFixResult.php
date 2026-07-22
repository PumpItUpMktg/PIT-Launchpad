<?php

namespace App\Publishing\Links;

/** The outcome of applying one {@see LinkFinding} via {@see InternalLinkFixer}. */
final class LinkFixResult
{
    public function __construct(
        public readonly bool $applied,
        public readonly string $message,
    ) {}

    public static function applied(string $message): self
    {
        return new self(true, $message);
    }

    public static function skipped(string $message): self
    {
        return new self(false, $message);
    }
}
