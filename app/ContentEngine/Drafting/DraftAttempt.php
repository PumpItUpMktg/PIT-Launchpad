<?php

namespace App\ContentEngine\Drafting;

/**
 * One drafter call: the raw model response exactly as returned, plus the payload
 * parsed from it. Keeping the raw response lets the engine record a truncated
 * excerpt on failure (an empty/malformed response is otherwise invisible) and
 * lets the drafter probe show what the model actually said.
 */
final class DraftAttempt
{
    public function __construct(
        public readonly string $rawResponse,
        public readonly DraftPayload $payload,
    ) {}
}
