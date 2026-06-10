<?php

use App\ContentEngine\Drafting\DraftFailedException;
use App\Enums\ContentStatus;
use App\Integrations\Claude\ClaudeClient;
use App\Integrations\Claude\CompletionResult;
use App\Models\Content;
use App\Models\Silo;
use App\Models\Site;
use Tests\Support\DraftingHarness;
use Tests\Support\FakeClaudeClient;

function failureCandidate(): Content
{
    $site = Site::factory()->create();
    $silo = Silo::factory()->create(['site_id' => $site->id]);

    return Content::factory()->post()->create([
        'site_id' => $site->id,
        'silo_id' => $silo->id,
        'matched_silo_id' => $silo->id,
        'status' => ContentStatus::Candidate,
        'body' => null,
    ]);
}

it('captures the underlying exception class, message and Anthropic HTTP status when the drafter call throws', function () {
    $candidate = failureCandidate();

    $boom = new class('overloaded') extends RuntimeException
    {
        public int $statusCode = 529;
    };
    $claude = new class($boom) implements ClaudeClient
    {
        public function __construct(private Throwable $e) {}

        public function complete(string $prompt, ?string $system = null): string
        {
            throw $this->e;
        }

        public function completeDetailed(string $prompt, ?string $system = null): CompletionResult
        {
            throw $this->e;
        }
    };

    try {
        DraftingHarness::engine($claude)->draftCandidate($candidate->fresh());
        $this->fail('expected DraftFailedException');
    } catch (DraftFailedException $e) {
        expect($e->failure)->not->toBeNull()
            ->and($e->failure->exceptionClass)->toBe($boom::class)
            ->and($e->failure->httpStatus)->toBe(529)
            ->and($e->failure->exceptionMessage)->toContain('overloaded');
    }

    $meta = $candidate->fresh()->meta;
    expect($candidate->fresh()->status)->toBe(ContentStatus::Candidate) // not flipped
        ->and($meta['draft_failure']['exception_class'])->toBe($boom::class)
        ->and($meta['draft_failure']['http_status'])->toBe(529)
        ->and($meta['draft_failure']['exception_message'])->toContain('overloaded')
        ->and($meta['draft_error'])->toContain('HTTP 529'); // human one-liner the queue shows
});

it('captures a truncated raw model response when the response does not parse into a draft', function () {
    $candidate = failureCandidate();

    // A non-JSON response parses to an empty payload — the silent-failure case.
    $claude = new FakeClaudeClient('Sorry, I cannot help with that request right now.');

    expect(fn () => DraftingHarness::engine($claude)->draftCandidate($candidate->fresh()))
        ->toThrow(DraftFailedException::class);

    $meta = $candidate->fresh()->meta;
    expect($meta['draft_failure']['exception_class'])->toBeNull()
        ->and($meta['draft_failure']['raw_response_excerpt'])->toContain('Sorry, I cannot help')
        ->and($meta['draft_error'])->toContain('no usable content');
});
