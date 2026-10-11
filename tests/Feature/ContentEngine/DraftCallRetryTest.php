<?php

use Anthropic\Core\Exceptions\APIConnectionException;
use App\ContentEngine\Drafting\DraftCall;
use App\Integrations\Claude\ClaudeClient;
use App\Integrations\Claude\CompletionResult;
use GuzzleHttp\Psr7\Request;

/** A client whose answers are scripted: a Throwable is thrown, anything else is returned as the completion text. */
function scriptedClaude(array $script, array &$calls): ClaudeClient
{
    return new class($script, $calls) implements ClaudeClient
    {
        /** @param  list<mixed>  $script */
        public function __construct(private array $script, public array &$calls) {}

        public function complete(string $prompt, ?string $system = null): string
        {
            return $this->completeDetailed($prompt, $system)->text;
        }

        public function completeDetailed(string $prompt, ?string $system = null): CompletionResult
        {
            $this->calls[] = $prompt;
            $next = array_shift($this->script);
            if ($next instanceof Throwable) {
                throw $next;
            }

            return new CompletionResult((string) $next, 'end_turn');
        }
    };
}

function droppedConnection(): APIConnectionException
{
    return new APIConnectionException(new Request('POST', 'https://api.anthropic.com/v1/messages'), previous: new RuntimeException('cURL error 56: connection reset'));
}

it('retries once after a FAST dropped connection, and the second answer is the draft', function () {
    $calls = [];
    $paused = [];
    $claude = scriptedClaude([droppedConnection(), '[[title]]Second try[[/title]]'], $calls);
    $call = new DraftCall($claude, clock: fn (): float => 10.0, pause: function (int $s) use (&$paused): void {
        $paused[] = $s;
    });

    $attempt = $call->attempt('system', 'prompt');

    expect($calls)->toHaveCount(2)
        ->and($paused)->toBe([DraftCall::RETRY_DELAY_SECONDS])
        ->and($attempt->rawResponse)->toContain('Second try');
});

it('does not retry a SLOW failure — that is the request timeout, and another would not fit the job', function () {
    $calls = [];
    $ticks = [0.0, 241.0]; // the first attempt "took" 241s
    $claude = scriptedClaude([droppedConnection(), 'never reached'], $calls);
    $call = new DraftCall($claude, clock: function () use (&$ticks): float {
        return array_shift($ticks) ?? 500.0;
    }, pause: fn (int $s) => null);

    expect(fn () => $call->attempt('system', 'prompt'))->toThrow(APIConnectionException::class);
    expect($calls)->toHaveCount(1);
});

it('gives up after the one retry so a real outage still surfaces as a failed draft', function () {
    $calls = [];
    $claude = scriptedClaude([droppedConnection(), droppedConnection(), 'never reached'], $calls);
    $call = new DraftCall($claude, clock: fn (): float => 1.0, pause: fn (int $s) => null);

    expect(fn () => $call->attempt('system', 'prompt'))->toThrow(APIConnectionException::class);
    expect($calls)->toHaveCount(2);
});

it('leaves every other exception alone — only a dropped connection is retried', function () {
    $calls = [];
    $claude = scriptedClaude([new RuntimeException('HTTP 529 overloaded'), 'never reached'], $calls);
    $call = new DraftCall($claude, clock: fn (): float => 1.0, pause: fn (int $s) => null);

    expect(fn () => $call->attempt('system', 'prompt'))->toThrow(RuntimeException::class, 'overloaded');
    expect($calls)->toHaveCount(1);
});
