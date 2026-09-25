<?php

use App\Enums\ContentStatus;
use App\Integrations\Embedding\EmbeddingProvider;
use App\Models\Content;
use App\Models\Silo;
use App\Models\Site;
use Tests\Support\Draft;
use Tests\Support\DraftingHarness;
use Tests\Support\FakeClaudeClient;
use Tests\Support\TopicEmbeddings;

function twinCandidate(Site $site, Silo $silo): Content
{
    return Content::factory()->post()->create([
        'site_id' => $site->id, 'silo_id' => $silo->id, 'matched_silo_id' => $silo->id,
        'status' => ContentStatus::Candidate, 'slug' => 'raccoon-story-tribunecom', 'wp_post_id' => null, 'body' => null,
    ]);
}

function twinDraft(string $title): FakeClaudeClient
{
    return new FakeClaudeClient(Draft::post('claim-x', [
        'seo' => ['title' => $title, 'meta_description' => 'x', 'slug' => 'ignored'],
        'body' => '<p>The article.</p>',
    ]));
}

beforeEach(function () {
    app()->instance(EmbeddingProvider::class, new TopicEmbeddings(['wildlife' => 'wildlife-maintenance']));
});

it('marks a first draft whose title reads like another post in the silo as its near-duplicate — hard when it is the same post', function () {
    $site = Site::factory()->create();
    $silo = Silo::factory()->create(['site_id' => $site->id]);
    $live = Content::factory()->post()->create([
        'site_id' => $site->id, 'silo_id' => $silo->id, 'status' => ContentStatus::Published,
        'title' => 'Sump Pump Maintenance Tips to Keep Wildlife Out', 'slug' => 'sump-pump-maintenance-tips-to-keep-wildlife-out', 'body' => '<p>x</p>',
    ]);

    $result = DraftingHarness::engine(twinDraft('Sump Pump Maintenance to Stop Wildlife and Water Damage'))
        ->draftCandidate(twinCandidate($site, $silo)->fresh());

    $draft = $result->content->fresh();
    expect($draft->status)->toBe(ContentStatus::NeedsReview)      // still drafted — the operator decides
        ->and($draft->near_dup_of_content_id)->toBe($live->id)
        ->and($draft->meta['near_dup']['hard'])->toBeTrue()
        ->and($draft->meta['near_dup']['twin_title'])->toBe('Sump Pump Maintenance Tips to Keep Wildlife Out');
});

it('a first draft on a distinct topic carries no twin, and a candidate alone in its silo skips the check', function () {
    $site = Site::factory()->create();
    $silo = Silo::factory()->create(['site_id' => $site->id]);
    Content::factory()->post()->create([
        'site_id' => $site->id, 'silo_id' => $silo->id, 'status' => ContentStatus::Published,
        'title' => 'Sump Pump Maintenance Tips to Keep Wildlife Out', 'slug' => 'wildlife', 'body' => '<p>x</p>',
    ]);

    $draft = DraftingHarness::engine(twinDraft('Where Should Sump Pump Water Go If Not the Street'))
        ->draftCandidate(twinCandidate($site, $silo)->fresh())->content->fresh();
    expect($draft->near_dup_of_content_id)->toBeNull()
        ->and($draft->meta['near_dup'] ?? null)->toBeNull();

    $lonely = Silo::factory()->create(['site_id' => $site->id]);
    $draft = DraftingHarness::engine(twinDraft('Sump Pump Maintenance to Stop Wildlife and Water Damage'))
        ->draftCandidate(twinCandidate($site, $lonely)->fresh())->content->fresh();
    expect($draft->near_dup_of_content_id)->toBeNull();
});
