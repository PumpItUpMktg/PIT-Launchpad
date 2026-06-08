<?php

namespace Tests\Support;

use App\Integrations\Claude\ClaudeClient;

/**
 * A canned ClaudeClient for tests — returns a fixed response and records the
 * prompts it was asked, so the topical clusterer can be exercised without any
 * network call.
 */
class FakeClaudeClient implements ClaudeClient
{
    /** @var list<string> */
    public array $prompts = [];

    public function __construct(private readonly string $response) {}

    public function complete(string $prompt, ?string $system = null): string
    {
        $this->prompts[] = $prompt;

        return $this->response;
    }
}
