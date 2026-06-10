<?php

namespace App\ContentEngine\Drafting;

use App\Integrations\Claude\CompletionResult;

/**
 * One drafter call: the raw model response exactly as returned, the payload
 * parsed from it, and the completion metadata (stop_reason + token usage).
 * Keeping the raw response lets the engine record a truncated excerpt on failure
 * (an empty/malformed response is otherwise invisible); the completion metadata
 * makes budget exhaustion observable instead of a "0 chars" mystery.
 */
final class DraftAttempt
{
    public function __construct(
        public readonly string $rawResponse,
        public readonly DraftPayload $payload,
        public readonly CompletionResult $completion,
    ) {}
}
