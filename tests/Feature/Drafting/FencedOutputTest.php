<?php

use App\Enums\ContentStatus;
use App\Models\Content;
use App\Models\Silo;
use App\Models\Site;
use Tests\Support\Draft;
use Tests\Support\DraftingHarness;
use Tests\Support\FakeClaudeClient;

function fencedCandidate(): Content
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

it('parses JSON the model wrapped in a ```json code fence', function () {
    $candidate = fencedCandidate();
    $json = Draft::post('claim-x', ['body' => '<p>Fenced but valid.</p>']);
    $claude = new FakeClaudeClient("```json\n{$json}\n```");

    $result = DraftingHarness::engine($claude)->draftCandidate($candidate->fresh());

    expect($result->content->status)->toBe(ContentStatus::NeedsReview)
        ->and($result->content->body)->toBe('<p>Fenced but valid.</p>');
});

it('parses fenced JSON even when prose with a stray brace precedes the fence', function () {
    $candidate = fencedCandidate();
    $json = Draft::post('claim-x', ['body' => '<p>Still parses.</p>']);
    // The prose brace is exactly what poisoned the old first-{-to-last-} span.
    $claude = new FakeClaudeClient("Sure — here it is using a {key: value} shape:\n\n```json\n{$json}\n```\n\nHope that helps!");

    $result = DraftingHarness::engine($claude)->draftCandidate($candidate->fresh());

    expect($result->content->status)->toBe(ContentStatus::NeedsReview)
        ->and($result->content->body)->toBe('<p>Still parses.</p>');
});
