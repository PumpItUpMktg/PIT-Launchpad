<?php

namespace App\ContentEngine\Drafting;

use RuntimeException;

/**
 * Raised when the drafter returns no usable content (empty body / empty slots) —
 * almost always a failed or malformed Sonnet response. It exists so the silent
 * failure becomes loud: the engine never flips a candidate to needs_review on an
 * empty draft, and callers (the command, the Filament action) catch this to
 * surface the failure instead of leaving an undrafted row masquerading as ready.
 */
class DraftFailedException extends RuntimeException
{
    public function __construct(
        public readonly ?string $contentId,
        string $message,
    ) {
        parent::__construct($message);
    }

    public static function emptyDraft(?string $contentId): self
    {
        return new self(
            $contentId,
            'The drafter produced no content (empty body/slots) — the model call likely failed or '
            .'returned malformed JSON. The candidate was left undrafted; retry generation.',
        );
    }
}
