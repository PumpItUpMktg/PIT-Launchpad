<?php

use App\ContentEngine\Generation\PostGenerator;
use App\Enums\ContentStatus;
use App\Models\Content;
use App\Models\Scopes\SiteScope;
use App\Models\Silo;
use App\Models\Site;
use App\Publishing\RenderCoordinator;
use App\Publishing\RenderOutcome;
use Illuminate\Support\Collection;
use Tests\Support\Draft;
use Tests\Support\DraftingHarness;
use Tests\Support\FakeClaudeClient;

function newsCandidate(Site $site, Silo $silo): Content
{
    return Content::factory()->post()->create([
        'site_id' => $site->id,
        'silo_id' => $silo->id,
        'matched_silo_id' => $silo->id,
        'status' => ContentStatus::Candidate,
        'title' => 'Heat pump rebate expands',
        'source_name' => 'Austin Tribune',
        'source_url' => null,
    ]);
}

it('drafts a candidate in place (→ needs_review) and renders its image — only when invoked', function () {
    $site = Site::factory()->create();
    $silo = Silo::factory()->create(['site_id' => $site->id]);
    $candidate = newsCandidate($site, $silo);

    $claude = new FakeClaudeClient(Draft::post('claim-x', [
        'images' => [['slot' => 'hero', 'prompt' => 'a modern heat pump', 'seo_filename' => 'heat-pump', 'alt' => 'A heat pump']],
    ]));
    $renders = Mockery::mock(RenderCoordinator::class);
    $renders->shouldReceive('render')
        ->once()
        ->withArgs(fn (Content $c) => ! empty($c->meta['image_specs'])) // image specs present at render time
        ->andReturn(new RenderOutcome(new Collection, true, []));

    // Gate: the candidate is undrafted until the action fires.
    expect($candidate->status)->toBe(ContentStatus::Candidate)
        ->and($claude->prompts)->toBe([]);

    $result = (new PostGenerator(DraftingHarness::engine($claude), $renders))->generate($candidate->fresh());

    // Same row, drafted in place — no duplicate Content.
    expect(Content::withoutGlobalScope(SiteScope::class)->where('site_id', $site->id)->count())->toBe(1);

    $drafted = $candidate->fresh();
    expect($drafted->id)->toBe($result->content->id)
        ->and($drafted->status)->toBe(ContentStatus::NeedsReview)
        ->and($drafted->silo_id)->toBe($silo->id)
        ->and($drafted->body)->not->toBeNull()
        ->and($drafted->meta['image_specs'])->not->toBeEmpty()
        ->and($claude->prompts)->toHaveCount(1); // the gated Sonnet call fired exactly once
});
