<?php

use App\Enums\ContentStatus;
use App\Models\Content;
use App\Models\Silo;
use App\Models\Site;
use Tests\Support\Draft;
use Tests\Support\DraftingHarness;
use Tests\Support\FakeClaudeClient;

function slugCandidate(array $overrides = []): Content
{
    $site = Site::factory()->create();
    $silo = Silo::factory()->create(['site_id' => $site->id]);

    return Content::factory()->post()->create(array_merge([
        'site_id' => $site->id,
        'silo_id' => $silo->id,
        'matched_silo_id' => $silo->id,
        'status' => ContentStatus::Candidate,
        'slug' => 'tankless-rebate-story-entrepreneurcom', // the news-ingest slug
        'wp_post_id' => null,                              // never published
        'body' => null,
    ], $overrides));
}

it('derives a first-draft slug from the SEO title, not the news-ingest slug', function () {
    $candidate = slugCandidate();
    $claude = new FakeClaudeClient(Draft::post('claim-x', [
        'seo' => ['title' => 'Tankless Water Heater Rebates Explained', 'meta_description' => 'x', 'slug' => 'ignored-seo-slug'],
        'body' => '<p>The fix.</p>',
    ]));

    $result = DraftingHarness::engine($claude)->draftCandidate($candidate->fresh());

    expect($result->content->slug)->toBe('tankless-water-heater-rebates-explained')
        ->and($result->content->slug)->not->toContain('entrepreneurcom');
});

it('never changes the slug of an already-published post on re-draft (live URL is immutable)', function () {
    $candidate = slugCandidate([
        'slug' => 'an-established-live-url',
        'wp_post_id' => 9991, // already published to WordPress
    ]);
    $claude = new FakeClaudeClient(Draft::post('claim-x', [
        'seo' => ['title' => 'A Totally Different Title', 'meta_description' => 'x', 'slug' => 'whatever'],
        'body' => '<p>Refreshed.</p>',
    ]));

    $result = DraftingHarness::engine($claude)->draftCandidate($candidate->fresh());

    expect($result->content->slug)->toBe('an-established-live-url');
});
