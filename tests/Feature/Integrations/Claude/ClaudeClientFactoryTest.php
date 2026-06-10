<?php

use App\Integrations\Claude\ClaudeClientFactory;

it('builds the drafting client with a capped thinking budget below a larger max_tokens', function () {
    $d = app(ClaudeClientFactory::class)->drafting()->describe();

    expect($d['thinking'])->toBe('enabled')
        ->and($d['max_tokens'])->toBe(12000)
        ->and($d['thinking_budget'])->toBe(4000)
        // The API contract: budget ≥ 1024 and strictly below max_tokens.
        ->and($d['thinking_budget'])->toBeGreaterThanOrEqual(1024)
        ->and($d['thinking_budget'])->toBeLessThan($d['max_tokens']);
});

it('emits an enabled thinking budget in the drafting payload', function () {
    $payload = app(ClaudeClientFactory::class)->drafting()->payload('hi', 'sys');

    expect($payload['thinking'])->toBe(['type' => 'enabled', 'budget_tokens' => 4000])
        ->and($payload['maxTokens'])->toBe(12000);
});

it('leaves the scoring client on Haiku with no thinking', function () {
    $s = app(ClaudeClientFactory::class)->scoring()->describe();

    expect($s['thinking'])->toBeNull()
        ->and($s['thinking_budget'])->toBeNull();
});
