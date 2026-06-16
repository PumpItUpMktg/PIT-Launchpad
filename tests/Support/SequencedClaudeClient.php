<?php

namespace Tests\Support;

use App\Integrations\Claude\ClaudeClient;
use App\Integrations\Claude\CompletionResult;

/**
 * A ClaudeClient test double that returns a scripted sequence of responses (one per
 * call), so a multi-turn conversation can be driven deterministically without any
 * network call. When the script is exhausted it keeps returning the final response.
 */
class SequencedClaudeClient implements ClaudeClient
{
    /** @var list<string> */
    public array $prompts = [];

    private int $index = 0;

    /**
     * @param  list<string>  $responses
     */
    public function __construct(private readonly array $responses) {}

    public function complete(string $prompt, ?string $system = null): string
    {
        return $this->completeDetailed($prompt, $system)->text;
    }

    public function completeDetailed(string $prompt, ?string $system = null): CompletionResult
    {
        $this->prompts[] = $prompt;

        $text = $this->responses === []
            ? ''
            : ($this->responses[$this->index] ?? $this->responses[array_key_last($this->responses)]);
        $this->index++;

        return new CompletionResult(text: $text, stopReason: 'end_turn');
    }
}
