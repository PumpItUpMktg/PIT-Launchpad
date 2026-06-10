<?php

namespace App\Integrations\Claude;

/**
 * A completion plus the metadata needed to diagnose it: why the model stopped
 * (stop_reason) and how the token budget was spent (input / output, and the
 * thinking slice of output). This is what makes budget exhaustion observable —
 * an empty text with stop_reason=max_tokens means thinking consumed the budget
 * before any text, rather than a silent "0 chars" mystery.
 */
final class CompletionResult
{
    public function __construct(
        public readonly string $text,
        public readonly ?string $stopReason = null,
        public readonly ?int $inputTokens = null,
        public readonly ?int $outputTokens = null,
        public readonly ?int $thinkingTokens = null,
    ) {}
}
