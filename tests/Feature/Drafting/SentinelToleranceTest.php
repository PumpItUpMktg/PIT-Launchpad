<?php

use App\Enums\ContentStatus;
use App\Models\Content;
use App\Models\Silo;
use App\Models\Site;
use Tests\Support\Draft;
use Tests\Support\DraftingHarness;
use Tests\Support\FakeClaudeClient;

function sentinelCandidate(): Content
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

it('extracts the draft when the model wraps its sentinel blocks in prose', function () {
    $candidate = sentinelCandidate();
    $draft = Draft::post('claim-x', ['body' => '<p>Wrapped but valid.</p>']);
    $claude = new FakeClaudeClient("Sure — here's the draft:\n\n{$draft}\n\nLet me know if you'd like changes.");

    $result = DraftingHarness::engine($claude)->draftCandidate($candidate->fresh());

    expect($result->content->status)->toBe(ContentStatus::NeedsReview)
        ->and($result->content->body)->toBe('<p>Wrapped but valid.</p>');
});

it('extracts the draft even when prose with a stray brace and a leftover fence precede the blocks', function () {
    $candidate = sentinelCandidate();
    $draft = Draft::post('claim-x', ['body' => '<p>Still parses.</p>']);
    // A stray brace + a leftover code fence in the preamble poisoned the old
    // first-{-to-last-} JSON span; sentinel extraction ignores all of it.
    $claude = new FakeClaudeClient("Using a {key: value} note and ```a fence```:\n\n{$draft}\n\nHope that helps!");

    $result = DraftingHarness::engine($claude)->draftCandidate($candidate->fresh());

    expect($result->content->status)->toBe(ContentStatus::NeedsReview)
        ->and($result->content->body)->toBe('<p>Still parses.</p>');
});
