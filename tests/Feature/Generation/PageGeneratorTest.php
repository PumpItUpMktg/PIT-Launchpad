<?php

use App\ContentEngine\Drafting\DraftCall;
use App\ContentEngine\Drafting\DraftGuard;
use App\ContentEngine\Drafting\PageDrafter;
use App\ContentEngine\Drafting\PageDraftingEngine;
use App\ContentEngine\Drafting\PageGroundingAssembler;
use App\ContentEngine\Generation\PageGenerator;
use App\Enums\ContentStatus;
use App\Models\Content;
use App\Models\ProofItem;
use App\Models\Scopes\SiteScope;
use App\PageBuilder\Validation\KitValidator;
use App\Publishing\RenderCoordinator;
use App\Publishing\RenderOutcome;
use Illuminate\Support\Collection;
use Tests\Support\FakeClaudeClient;
use Tests\Support\PageFixture;

it('drafts the page in place and renders its image-slots — only when invoked', function () {
    $page = PageFixture::intakePage();
    $proofId = (string) ProofItem::withoutGlobalScope(SiteScope::class)->where('site_id', $page->site_id)->value('id');

    $claude = new FakeClaudeClient(PageFixture::validResponse($proofId));
    $engine = new PageDraftingEngine(
        new PageGroundingAssembler,
        new PageDrafter(new DraftCall($claude)),
        new DraftGuard,
        app(KitValidator::class),
    );

    $renders = Mockery::mock(RenderCoordinator::class);
    $renders->shouldReceive('render')
        ->once()
        ->withArgs(fn (Content $c) => ! empty($c->meta['image_specs'])) // specs present at render time
        ->andReturn(new RenderOutcome(new Collection, true, []));

    // Gate: undrafted until invoked.
    expect($page->hasDraft())->toBeFalse();

    $result = (new PageGenerator($engine, $renders))->generate($page->fresh());

    expect($result->status)->toBe(ContentStatus::NeedsReview)
        ->and($result->hasDraft())->toBeTrue()
        ->and($result->slot_payload['hero_problem'])->toContain('hot water');
});
