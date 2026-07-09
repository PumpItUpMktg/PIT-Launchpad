<?php

use App\Integrations\Claude\ClaudeClient;
use App\Integrations\Claude\CompletionResult;
use App\Onboarding\MissionPolisher;
use Tests\Support\FakeClaudeClient;

/** A ClaudeClient that always fails — the API-down case the polisher must fail open through. */
function throwingClaude(): ClaudeClient
{
    return new class implements ClaudeClient
    {
        public function complete(string $prompt, ?string $system = null): string
        {
            throw new RuntimeException('api down');
        }

        public function completeDetailed(string $prompt, ?string $system = null): CompletionResult
        {
            throw new RuntimeException('api down');
        }
    };
}

it('polishes the raw wording through the constrained prompt', function () {
    $fake = new FakeClaudeClient('We fix every drain right the first time, and we stand behind the work.');

    $polished = (new MissionPolisher($fake))->polish('we fix drains rite the first time and stand behind are work');

    expect($polished)->toBe('We fix every drain right the first time, and we stand behind the work.')
        // the client's words travel in the prompt; the honesty constraints live in the system prompt
        ->and($fake->prompts[0])->toContain('we fix drains rite the first time');
});

it('normalizes model wrapping — fences, quotes, and multi-line output collapse to one bare statement', function () {
    $fenced = new FakeClaudeClient("```\n\"We show up on time,\nevery time.\"\n```");

    expect((new MissionPolisher($fenced))->polish('we show up on time every time'))
        ->toBe('We show up on time, every time.');
});

it('fails open: an API failure returns null so the caller stores the verbatim wording', function () {
    expect((new MissionPolisher(throwingClaude()))->polish('our mission'))->toBeNull();
});

it('rejects degenerate output — empty or overlong results are a failed polish, not a mission', function () {
    expect((new MissionPolisher(new FakeClaudeClient('')))->polish('our mission'))->toBeNull()
        ->and((new MissionPolisher(new FakeClaudeClient(str_repeat('very long marketing essay ', 30))))->polish('our mission'))->toBeNull();
});

it('returns null for empty input without calling the API', function () {
    $fake = new FakeClaudeClient('should never be called');

    expect((new MissionPolisher($fake))->polish('   '))->toBeNull()
        ->and($fake->prompts)->toBe([]);
});
