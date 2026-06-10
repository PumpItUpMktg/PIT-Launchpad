<?php

use App\ContentEngine\Drafting\DraftCall;
use App\ContentEngine\Drafting\Drafter;
use App\ContentEngine\Drafting\PageDrafter;
use App\Enums\ContentStatus;
use App\Models\Content;
use App\Models\ProofItem;
use App\Models\Scopes\SiteScope;
use App\Models\Silo;
use App\Models\Site;
use Tests\Support\Draft;
use Tests\Support\FakeClaudeClient;
use Tests\Support\PageFixture;

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
    $this->app->bind(Drafter::class, fn () => new Drafter(new DraftCall(new FakeClaudeClient(Draft::post('claim-x')))));

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

    $this->app->bind(Drafter::class, fn () => new Drafter(new DraftCall(new FakeClaudeClient('Sorry, not JSON.'))));

    $this->artisan('launchpad:probe-drafter', ['content' => $candidate->id])
        ->expectsOutputToContain('DRAFT FAILED')
        ->assertFailed();

    expect($candidate->fresh()->status)->toBe(ContentStatus::Candidate); // still untouched
});

it('runs the live drafter path against a PAGE and reports DRAFTED', function () {
    config(['services.anthropic.key' => 'test-key']);
    $page = PageFixture::intakePage();
    $proofId = (string) ProofItem::withoutGlobalScope(SiteScope::class)
        ->where('site_id', $page->site_id)->value('id');

    // Override the PageDrafter the command resolves (the real page path).
    $this->app->bind(PageDrafter::class, fn () => new PageDrafter(
        new DraftCall(new FakeClaudeClient(PageFixture::validResponse($proofId))),
    ));

    $this->artisan('launchpad:probe-drafter', ['content' => $page->id])
        ->expectsOutputToContain('DRAFTED')
        ->assertSuccessful();

    expect($page->fresh()->status)->toBe(ContentStatus::Candidate); // read-only
});

it('fails cleanly when the candidate is not found', function () {
    $this->artisan('launchpad:probe-drafter', ['content' => '01ABCNOTREAL'])
        ->expectsOutputToContain('Content not found')
        ->assertFailed();
});
