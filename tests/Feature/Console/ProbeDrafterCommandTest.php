<?php

use App\ContentEngine\Drafting\Drafter;
use App\Enums\ContentStatus;
use App\Models\Content;
use App\Models\Silo;
use App\Models\Site;
use Tests\Support\Draft;
use Tests\Support\FakeClaudeClient;

function probeCandidate(): Content
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

it('runs the live drafter path and reports DRAFTED — read-only', function () {
    config(['services.anthropic.key' => 'test-key']);
    $candidate = probeCandidate();

    // Override the Drafter the command resolves from the container (the real path).
    $this->app->bind(Drafter::class, fn () => new Drafter(new FakeClaudeClient(Draft::post('claim-x'))));

    $this->artisan('launchpad:probe-drafter', ['content' => $candidate->id])
        ->expectsOutputToContain('DRAFTED')
        ->assertSuccessful();

    // The probe must never mutate the candidate.
    expect($candidate->fresh()->status)->toBe(ContentStatus::Candidate)
        ->and($candidate->fresh()->body)->toBeNull();
});

it('reports DRAFT FAILED with the cause when the response does not parse', function () {
    config(['services.anthropic.key' => 'test-key']);
    $candidate = probeCandidate();

    $this->app->bind(Drafter::class, fn () => new Drafter(new FakeClaudeClient('Sorry, not JSON.')));

    $this->artisan('launchpad:probe-drafter', ['content' => $candidate->id])
        ->expectsOutputToContain('DRAFT FAILED')
        ->assertFailed();

    expect($candidate->fresh()->status)->toBe(ContentStatus::Candidate); // still untouched
});

it('fails cleanly when the candidate is not found', function () {
    $this->artisan('launchpad:probe-drafter', ['content' => '01ABCNOTREAL'])
        ->expectsOutputToContain('Candidate not found')
        ->assertFailed();
});
