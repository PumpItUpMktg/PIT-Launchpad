<?php

use Anthropic\Core\Exceptions\APIConnectionException;
use App\ContentEngine\Drafting\DraftCall;
use App\ContentEngine\Drafting\DraftFailure;
use App\Integrations\Claude\ClaudeClient;
use App\Integrations\Claude\CompletionResult;
use App\Jobs\DraftPrioritySections;
use App\Jobs\GeneratePage;
use App\Jobs\GeneratePost;
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

it('retries a drop two minutes into a long generation — with the shipped budget a second request still fits', function () {
    $calls = [];
    $ticks = [0.0, 150.0]; // the connection was cut 150s in, mid-generation
    $claude = scriptedClaude([droppedConnection(), '[[title]]Second try[[/title]]'], $calls);
    $call = new DraftCall($claude, clock: function () use (&$ticks): float {
        return array_shift($ticks) ?? 500.0;
    }, pause: fn (int $s) => null, requestTimeout: 240, jobBudget: 600);

    expect($call->attempt('system', 'prompt')->rawResponse)->toContain('Second try')
        ->and($calls)->toHaveCount(2);
});

it('does not retry when the pause plus one more full request would not fit the job budget', function () {
    $calls = [];
    $ticks = [0.0, 100.0]; // 100 + 3 + 240 + 60 margin > 300
    $claude = scriptedClaude([droppedConnection(), 'never reached'], $calls);
    $call = new DraftCall($claude, clock: function () use (&$ticks): float {
        return array_shift($ticks) ?? 500.0;
    }, pause: fn (int $s) => null, requestTimeout: 240, jobBudget: 300);

    expect(fn () => $call->attempt('system', 'prompt'))->toThrow(APIConnectionException::class);
    expect($calls)->toHaveCount(1);
});

it('sizes the retry from the live config: the shipped request timeout and job budget leave room for any drop', function () {
    $calls = [];
    $call = new DraftCall(scriptedClaude([], $calls));
    $timeout = (int) config('services.anthropic.timeout');

    // Even a request that ran to its full timeout gets one retry.
    expect($call->retryFits((float) $timeout))->toBeTrue()
        ->and($call->retryFits((float) config('launchpad.drafting.job_budget_seconds')))->toBeFalse();
});

it('holds every drafting job at the shared budget the retry is sized against', function () {
    $budget = (int) config('launchpad.drafting.job_budget_seconds');
    $retryAfter = (int) config('queue.connections.database.retry_after');

    expect((new GeneratePost('x'))->timeout)->toBeGreaterThanOrEqual($budget)
        ->and((new GeneratePage('x'))->timeout)->toBeGreaterThanOrEqual($budget)
        ->and((new DraftPrioritySections('x'))->timeout)->toBeGreaterThanOrEqual($budget)
        ->and((new DraftPrioritySections('x'))->timeout)->toBeLessThan($retryAfter);
});

it('records the wrapped transport cause, not just the SDK\'s fixed description', function () {
    $failure = DraftFailure::fromException(droppedConnection());

    expect($failure->exceptionMessage)->toBe('Anthropic API Connection Error ← cURL error 56: connection reset')
        ->and($failure->summary())->toContain('cURL error 56');
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
