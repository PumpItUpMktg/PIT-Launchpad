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

it('bounds every client so timeout × (1 + retries) stays under the drafting job budget (no MaxAttemptsExceeded)', function () {
    // The GeneratePost/GeneratePage job $timeout is 600s and the queue retry_after is 630s; a Claude
    // call must not be able to outrun them (the SDK's own default is 600×2 ≈ 1800s).
    foreach (['drafting', 'scoring', 'default', 'expander'] as $seam) {
        $d = app(ClaudeClientFactory::class)->{$seam}()->describe();
        expect($d['timeout'] * (1 + $d['max_retries']))->toBeLessThan(600);
    }
});

it('reads the Claude timeout and retry bound from config', function () {
    config()->set('services.anthropic.timeout', 123);
    config()->set('services.anthropic.max_retries', 0);

    $d = app(ClaudeClientFactory::class)->drafting()->describe();

    expect($d['timeout'])->toBe(123)->and($d['max_retries'])->toBe(0);
});
