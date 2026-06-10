<?php

use App\ContentEngine\Drafting\Drafter;
use App\ContentEngine\Generation\PostGenerator;
use App\Enums\ContentStatus;
use App\Jobs\GeneratePost;
use App\Models\Content;
use App\Models\Silo;
use App\Models\Site;
use App\Publishing\RenderCoordinator;
use App\Publishing\RenderOutcome;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Bus;
use Tests\Support\Draft;
use Tests\Support\FakeClaudeClient;

function jobCandidate(): Content
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

function bindDrafter(FakeClaudeClient $claude): void
{
    app()->bind(Drafter::class, fn () => new Drafter($claude));
}

it('queue() stamps the row generating and dispatches the job without drafting inline', function () {
    Bus::fake();
    $candidate = jobCandidate();

    GeneratePost::queue($candidate, actorId: 'op-1');

    $fresh = $candidate->fresh();
    expect($fresh->isGenerating())->toBeTrue()
        ->and($fresh->generationState())->toBe('generating')
        ->and($fresh->status)->toBe(ContentStatus::Candidate); // not drafted in the request

    Bus::assertDispatched(GeneratePost::class, fn (GeneratePost $job) => $job->contentId === $candidate->id
        && $job->actorId === 'op-1');
});

it('handle() runs the draft on the worker — candidate → needs_review, generating cleared', function () {
    $candidate = jobCandidate();
    $candidate->markGenerating();

    bindDrafter(new FakeClaudeClient(Draft::post('claim-x', [
        'images' => [['slot' => 'hero', 'prompt' => 'a heat pump', 'seo_filename' => 'hp', 'alt' => 'A heat pump']],
    ])));
    $renders = Mockery::mock(RenderCoordinator::class);
    $renders->shouldReceive('render')->once()->andReturn(new RenderOutcome(new Collection, true, []));
    $this->app->instance(RenderCoordinator::class, $renders);

    (new GeneratePost($candidate->id))->handle(app(PostGenerator::class));

    $fresh = $candidate->fresh();
    expect($fresh->status)->toBe(ContentStatus::NeedsReview)
        ->and($fresh->hasDraft())->toBeTrue()
        ->and($fresh->isGenerating())->toBeFalse()
        ->and($fresh->generationState())->toBe('drafted');
});

it('handle() records the failure and clears generating when the draft fails', function () {
    $candidate = jobCandidate();
    $candidate->markGenerating();

    // Budget exhaustion — empty text, stop_reason=max_tokens.
    bindDrafter(new FakeClaudeClient('', stopReason: 'max_tokens', outputTokens: 12000, thinkingTokens: 8000));
    $renders = Mockery::mock(RenderCoordinator::class);
    $renders->shouldReceive('render')->never();
    $this->app->instance(RenderCoordinator::class, $renders);

    (new GeneratePost($candidate->id))->handle(app(PostGenerator::class));

    $fresh = $candidate->fresh();
    expect($fresh->status)->toBe(ContentStatus::Candidate) // not flipped
        ->and($fresh->draftError())->not->toBeNull()
        ->and($fresh->isGenerating())->toBeFalse()
        ->and($fresh->generationState())->toBe('failed');
});
