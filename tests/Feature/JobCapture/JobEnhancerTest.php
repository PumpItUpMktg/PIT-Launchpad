<?php

use App\Enums\JobStatus;
use App\Integrations\Claude\ClaudeClient;
use App\Integrations\Claude\CompletionResult;
use App\JobCapture\Enhancement\JobEnhancementException;
use App\JobCapture\Enhancement\JobEnhancer;
use App\Models\Job;
use App\Models\Site;
use Tests\Support\FakeClaudeClient;

/** A captured job ready to enhance: edited source, two photos, a job type, a branded site. */
function jobToEnhance(): Job
{
    $site = Site::factory()->create(['brand_name' => 'Sump Pump Gurus']);
    $job = Job::factory()->create([
        'site_id' => $site->id,
        'status' => JobStatus::Captured,
        'raw_description' => 'replaced sump pump',
        'source_description' => 'Replaced a failed sump pump and cleared the pit.',
        'photos' => [['r2_key' => 'k1'], ['r2_key' => 'k2']],
        'enhanced_description' => null,
    ]);
    $job->jobTypes()->create(['label' => 'Sump Pump Repair', 'slug' => 'sump-pump-repair']);

    return $job;
}

test('it enhances a job into description, title, meta, and per-photo alt text, then moves it to review', function () {
    $json = (string) json_encode([
        'title' => 'New Sump Pump Keeps a Basement Dry',
        'meta' => 'How we replaced a failed sump pump.',
        'description' => "First paragraph.\n\nSecond paragraph.",
        'alts' => ['A newly installed sump pump', 'The cleared pit'],
    ]);
    $job = jobToEnhance();

    (new JobEnhancer(new FakeClaudeClient($json)))->enhance($job);
    $job->refresh();

    expect($job->status)->toBe(JobStatus::Review)
        ->and($job->enhanced_description)->toContain('First paragraph')
        ->and($job->post_title)->toBe('New Sump Pump Keeps a Basement Dry')
        ->and($job->meta_description)->toContain('replaced a failed sump pump')
        ->and($job->photos[0]['alt'])->toBe('A newly installed sump pump')
        ->and($job->photos[1]['alt'])->toBe('The cleared pit');
});

test('it reads source_description (not raw) and grounds the prompt in real job facts', function () {
    $fake = new FakeClaudeClient((string) json_encode(['title' => 't', 'meta' => 'm', 'description' => 'd', 'alts' => []]));
    $job = jobToEnhance();

    (new JobEnhancer($fake))->enhance($job);

    expect($fake->prompts[0])->toContain('Replaced a failed sump pump')   // the edited source, not the raw input
        ->and($fake->prompts[0])->toContain('Sump Pump Repair')
        ->and($fake->prompts[0])->toContain('Sump Pump Gurus');
});

test('an empty write-up does not advance the job and throws', function () {
    $job = jobToEnhance();

    expect(fn () => (new JobEnhancer(new FakeClaudeClient('{"title":"t"}')))->enhance($job))
        ->toThrow(JobEnhancementException::class);

    expect($job->refresh()->status)->toBe(JobStatus::Captured)
        ->and($job->enhanced_description)->toBeNull();
});

test('a thrown model call reverts the job to captured (never stranded at enhancing) and re-throws', function () {
    // The model call fails outright (API error / timeout) — the job must not be left at Enhancing, where the
    // review queue would never surface it. It reverts to Captured, visible and re-enhanceable.
    $throwing = new class implements ClaudeClient
    {
        public function complete(string $prompt, ?string $system = null): string
        {
            throw new RuntimeException('Claude API timeout');
        }

        public function completeDetailed(string $prompt, ?string $system = null): CompletionResult
        {
            throw new RuntimeException('Claude API timeout');
        }
    };
    $job = jobToEnhance();

    expect(fn () => (new JobEnhancer($throwing))->enhance($job))
        ->toThrow(RuntimeException::class);

    expect($job->refresh()->status)->toBe(JobStatus::Captured)
        ->and($job->enhanced_description)->toBeNull();
});

test('it tolerates a fenced JSON response', function () {
    $fenced = "```json\n".json_encode(['title' => 'T', 'meta' => 'M', 'description' => 'Grounded copy.', 'alts' => []])."\n```";
    $job = jobToEnhance();

    (new JobEnhancer(new FakeClaudeClient($fenced)))->enhance($job);

    expect($job->refresh()->enhanced_description)->toBe('Grounded copy.');
});

test('the enhance-job command enhances a job through the bound enhancer', function () {
    app()->instance(JobEnhancer::class, new JobEnhancer(new FakeClaudeClient(
        (string) json_encode(['title' => 'T', 'meta' => 'M', 'description' => 'D', 'alts' => []])
    )));
    $job = jobToEnhance();

    $this->artisan('launchpad:enhance-job', ['job' => $job->id])->assertSuccessful();

    expect($job->refresh()->status)->toBe(JobStatus::Review);
});
