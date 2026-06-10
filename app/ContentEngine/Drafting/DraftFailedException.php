<?php

namespace App\ContentEngine\Drafting;

use RuntimeException;
use Throwable;

/**
 * Raised when the drafter returns no usable content (empty body / empty slots)
 * or its call throws — almost always a failed/malformed model response. It
 * exists so the silent failure becomes loud: the engine never flips a candidate
 * to needs_review on a failed draft, and callers (the command, the Filament
 * action, the drafter probe) catch this to surface the cause carried on
 * `$failure` instead of leaving an undrafted row masquerading as ready.
 */
class DraftFailedException extends RuntimeException
{
    public function __construct(
        public readonly ?string $contentId,
        string $message,
        public readonly ?DraftFailure $failure = null,
        ?Throwable $previous = null,
    ) {
        parent::__construct($message, 0, $previous);
    }

    public static function fromFailure(?string $contentId, DraftFailure $failure, ?Throwable $previous = null): self
    {
        return new self($contentId, $failure->summary(), $failure, $previous);
    }
}
