<?php

use Anthropic\Core\BaseClient;
use App\Integrations\Claude\AnthropicClaudeClient;
use Symfony\Component\HttpClient\Psr18Client;

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

test('every request ends with the user message (no assistant prefill)', function () {
    $client = new AnthropicClaudeClient('test-key', 'claude-opus-4-8', 16000, thinking: 'adaptive');

    $payload = $client->payload('expand this');

    expect($payload['messages'])->toHaveCount(1)
        ->and($payload['messages'][0]['role'])->toBe('user')
        ->and($payload['maxTokens'])->toBe(16000);
});

test('the SDK transport carries the configured timeout — idle and total — instead of PHP\'s 60s socket default', function () {
    $transporter = AnthropicClaudeClient::transporter(240);

    expect($transporter)->toBeInstanceOf(Psr18Client::class);

    $http = (new ReflectionProperty($transporter, 'client'))->getValue($transporter);
    $options = (new ReflectionProperty($http, 'defaultOptions'))->getValue($http);

    expect($options['timeout'])->toBe(240.0)
        ->and($options['max_duration'])->toBe(240.0);
});

test('the SDK client is built on that transport, not the discovered default', function () {
    $client = (new AnthropicClaudeClient('test-key', 'claude-sonnet-4-6', timeout: 240))->sdk();

    $options = (new ReflectionProperty(BaseClient::class, 'options'))->getValue($client);

    expect($options->transporter)->toBeInstanceOf(Psr18Client::class)
        ->and($options->timeout)->toBe(240.0)
        ->and($options->maxRetries)->toBe(1);
});
