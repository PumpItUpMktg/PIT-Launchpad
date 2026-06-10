<?php

use App\ContentEngine\Drafting\DraftCall;
use App\ContentEngine\Drafting\PageDrafter;
use App\Enums\ContentStatus;
use App\Models\Content;
use App\Models\ProofItem;
use App\Models\Scopes\SiteScope;
use App\Publishing\RenderCoordinator;
use App\Publishing\RenderOutcome;
use Illuminate\Support\Collection;
use Tests\Support\FakeClaudeClient;
use Tests\Support\PageFixture;

function fakeRenders(): void
{
    $renders = Mockery::mock(RenderCoordinator::class);
    $renders->shouldReceive('render')->andReturn(new RenderOutcome(new Collection, true, []));
    app()->instance(RenderCoordinator::class, $renders);
}

it('generates a single page via the command', function () {
    $page = PageFixture::intakePage();
    $proofId = (string) ProofItem::withoutGlobalScope(SiteScope::class)->where('site_id', $page->site_id)->value('id');
    app()->bind(PageDrafter::class, fn () => new PageDrafter(new DraftCall(new FakeClaudeClient(PageFixture::validResponse($proofId)))));
    fakeRenders();

    test()->artisan('launchpad:generate-page', ['content' => $page->id])->assertSuccessful();

    expect($page->fresh()->status)->toBe(ContentStatus::NeedsReview);
});

it('refuses a non-page Content', function () {
    $post = Content::factory()->post()->create();

    test()->artisan('launchpad:generate-page', ['content' => $post->id])
        ->expectsOutputToContain('not a page')
        ->assertFailed();
});

it('iterates a site\'s undrafted pages with --site', function () {
    $page = PageFixture::intakePage();
    $proofId = (string) ProofItem::withoutGlobalScope(SiteScope::class)->where('site_id', $page->site_id)->value('id');
    app()->bind(PageDrafter::class, fn () => new PageDrafter(new DraftCall(new FakeClaudeClient(PageFixture::validResponse($proofId)))));
    fakeRenders();

    test()->artisan('launchpad:generate-page', ['--site' => $page->site_id])->assertSuccessful();

    expect($page->fresh()->status)->toBe(ContentStatus::NeedsReview);
});
