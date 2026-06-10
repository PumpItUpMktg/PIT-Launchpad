<?php

namespace Tests\Support;

use App\Integrations\Claude\ClaudeClient;
use App\Integrations\Claude\CompletionResult;

/**
 * A canned ClaudeClient for tests — returns a fixed response and records the
 * prompts it was asked, so the topical clusterer can be exercised without any
 * network call. Optional stop_reason / token usage let a test simulate budget
 * exhaustion (empty text + stop_reason=max_tokens).
 */
class FakeClaudeClient implements ClaudeClient
{
    /** @var list<string> */
    public array $prompts = [];

    public function __construct(
        private readonly string $response,
        private readonly ?string $stopReason = 'end_turn',
        private readonly ?int $outputTokens = null,
        private readonly ?int $thinkingTokens = null,
    ) {}

    public function complete(string $prompt, ?string $system = null): string
    {
        return $this->completeDetailed($prompt, $system)->text;
    }

    public function completeDetailed(string $prompt, ?string $system = null): CompletionResult
    {
        $this->prompts[] = $prompt;

        return new CompletionResult(
            text: $this->response,
            stopReason: $this->stopReason,
            outputTokens: $this->outputTokens,
            thinkingTokens: $this->thinkingTokens,
        );
    }
}
