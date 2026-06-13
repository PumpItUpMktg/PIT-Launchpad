<?php

use Anthropic\Messages\OutputTokensDetails;
use Anthropic\Messages\Usage;
use App\Integrations\Claude\AnthropicClaudeClient;

/**
 * Regression for the live SPG pillar crash: the Anthropic PHP SDK (v0.27) declares
 * `?OutputTokensDetails $outputTokensDetails` with NO default, so when the API
 * response omits it the property is UNINITIALIZED (not null) — any direct read
 * (even via the null-safe `?->`) throws "must not be accessed before
 * initialization", which stranded a successful draft mid-generation. FakeClaudeClient
 * never exercises real Usage, so this was invisible to the suite.
 */
it('reproduces the crash: a direct read of an uninitialized outputTokensDetails throws', function () {
    $usage = (new ReflectionClass(Usage::class))->newInstanceWithoutConstructor();
    $usage->inputTokens = 120;
    $usage->outputTokens = 340;
    // outputTokensDetails deliberately left uninitialized.

    expect(fn () => $usage->outputTokensDetails?->thinkingTokens)->toThrow(Error::class);
});

it('reads usage safely when outputTokensDetails is uninitialized (returns null thinking, no throw)', function () {
    $usage = (new ReflectionClass(Usage::class))->newInstanceWithoutConstructor();
    $usage->inputTokens = 120;
    $usage->outputTokens = 340;

    expect(AnthropicClaudeClient::readUsage($usage))
        ->toBe(['input' => 120, 'output' => 340, 'thinking' => null]);
});

it('still reads thinking tokens when the details are present', function () {
    $details = (new ReflectionClass(OutputTokensDetails::class))->newInstanceWithoutConstructor();
    $details->thinkingTokens = 42;

    $usage = (new ReflectionClass(Usage::class))->newInstanceWithoutConstructor();
    $usage->inputTokens = 1;
    $usage->outputTokens = 2;
    $usage->outputTokensDetails = $details;

    expect(AnthropicClaudeClient::readUsage($usage))
        ->toBe(['input' => 1, 'output' => 2, 'thinking' => 42]);
});

it('tolerates details present but thinkingTokens itself uninitialized', function () {
    $details = (new ReflectionClass(OutputTokensDetails::class))->newInstanceWithoutConstructor();
    // thinkingTokens left uninitialized.

    $usage = (new ReflectionClass(Usage::class))->newInstanceWithoutConstructor();
    $usage->inputTokens = 5;
    $usage->outputTokens = 6;
    $usage->outputTokensDetails = $details;

    expect(AnthropicClaudeClient::readUsage($usage)['thinking'])->toBeNull();
});
