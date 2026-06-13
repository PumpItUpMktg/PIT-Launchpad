<?php

use App\ContentEngine\Drafting\DraftFailedException;
use App\ContentEngine\Generation\PostGenerator;
use App\Enums\ContentKind;
use App\Enums\ContentStatus;
use App\Enums\PageType;
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

it('does NOT flip a candidate to needs_review when the draft comes back empty — and surfaces it', function () {
    $site = Site::factory()->create();
    $silo = Silo::factory()->create(['site_id' => $site->id]);
    $candidate = newsCandidate($site, $silo);
    $candidate->update(['body' => null]); // a real news candidate carries no body

    // An empty / unparseable model response yields no draft.
    $claude = new FakeClaudeClient('');
    $renders = Mockery::mock(RenderCoordinator::class);
    $renders->shouldReceive('render')->never(); // never burn a fal render on a failed draft

    expect(fn () => (new PostGenerator(DraftingHarness::engine($claude), $renders))->generate($candidate->fresh()))
        ->toThrow(DraftFailedException::class);

    $after = $candidate->fresh();
    expect($after->status)->toBe(ContentStatus::Candidate) // NOT needs_review
        ->and($after->body)->toBeNull()
        ->and($after->hasDraft())->toBeFalse()
        ->and($after->draftError())->not->toBeNull(); // failure marker persisted for the surfaces
});

it('refuses to draft a page through the post lane — never flips kind=page to a post', function () {
    $site = Site::factory()->create();
    $silo = Silo::factory()->create(['site_id' => $site->id]);
    // A §4 pillar stub: kind=page, status=candidate — must NOT be drafted as a post.
    $page = Content::factory()->page()->create([
        'site_id' => $site->id,
        'silo_id' => $silo->id,
        'status' => ContentStatus::Candidate,
        'page_type' => PageType::Service,
    ]);

    $renders = Mockery::mock(RenderCoordinator::class);
    $renders->shouldReceive('render')->never(); // guard fires before any draft/render

    expect(fn () => (new PostGenerator(DraftingHarness::engine(new FakeClaudeClient('')), $renders))->generate($page->fresh()))
        ->toThrow(InvalidArgumentException::class);

    expect($page->fresh()->kind)->toBe(ContentKind::Page); // unflipped
});

it('titles the drafted post from the generated SEO title, not the carried news headline', function () {
    $site = Site::factory()->create();
    $silo = Silo::factory()->create(['site_id' => $site->id]);
    $candidate = newsCandidate($site, $silo); // carried title: 'Heat pump rebate expands'

    $claude = new FakeClaudeClient(Draft::post('claim-x', [
        'seo' => [
            'title' => 'What the Heat-Pump Rebate Means for Your Energy Bill',
            'meta_description' => 'A homeowner rundown.',
            'slug' => 'heat-pump-rebate-energy-bill',
        ],
    ]));
    $renders = Mockery::mock(RenderCoordinator::class);
    $renders->shouldReceive('render')->once()->andReturn(new RenderOutcome(new Collection, true, []));

    $result = (new PostGenerator(DraftingHarness::engine($claude), $renders))->generate($candidate->fresh());

    expect($result->content->fresh()->title)->toBe('What the Heat-Pump Rebate Means for Your Energy Bill');
});
