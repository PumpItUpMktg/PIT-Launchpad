<?php

namespace Tests\Support;

use App\Integrations\Claude\ClaudeClient;
use App\Integrations\Claude\CompletionResult;

/**
 * A ClaudeClient whose response is chosen by a substring found in the prompt
 * (the news item's title is embedded in the relevance prompt), so each item can
 * be scripted independently without any network call.
 */
class ScriptedClaudeClient implements ClaudeClient
{
    /** @var array<string, string> */
    private array $map = [];

    private string $fallback = '{"relevance":0,"matched_silo":null,"brand_safe":true}';

    public function on(string $needle, string $response): static
    {
        $this->map[$needle] = $response;

        return $this;
    }

    public function fallback(string $response): static
    {
        $this->fallback = $response;

        return $this;
    }

    public function complete(string $prompt, ?string $system = null): string
    {
        return $this->completeDetailed($prompt, $system)->text;
    }

    public function completeDetailed(string $prompt, ?string $system = null): CompletionResult
    {
        foreach ($this->map as $needle => $response) {
            if (str_contains($prompt, $needle)) {
                return new CompletionResult($response, 'end_turn');
            }
        }

        return new CompletionResult($this->fallback, 'end_turn');
    }
}
