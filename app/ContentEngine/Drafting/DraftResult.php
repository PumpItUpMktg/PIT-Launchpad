<?php

namespace App\ContentEngine\Drafting;

use App\Models\Content;

/**
 * The outcome of a drafting run: the emitted/updated Content row (status
 * needs_review), plus the raw payload and verification result for callers that
 * want them without re-reading the row.
 */
final class DraftResult
{
    public function __construct(
        public readonly Content $content,
        public readonly DraftPayload $payload,
        public readonly VerificationResult $verification,
        public readonly bool $wasRefresh,
    ) {}
}
