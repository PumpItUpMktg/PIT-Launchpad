<?php

use App\Integrations\Claude\AnthropicClaudeClient;

test('the scoring call site sends no thinking param (Haiku has none)', function () {
    $client = new AnthropicClaudeClient('test-key', 'claude-haiku-4-5', 4096, thinking: null);

    $payload = $client->payload('score this item');

    expect($payload)->not->toHaveKey('thinking')
        ->and($payload['model'])->toBe('claude-haiku-4-5')
        ->and($payload['maxTokens'])->toBe(4096);
});

test('the drafting / default call site keeps adaptive thinking', function () {
    $client = new AnthropicClaudeClient('test-key', 'claude-sonnet-4-6');

    $payload = $client->payload('draft this', 'a system prompt');

    expect($payload['thinking'])->toBe(['type' => 'adaptive'])
        ->and($payload['system'])->toBe('a system prompt')
        ->and($payload['messages'][0]['content'])->toBe('draft this');
});
