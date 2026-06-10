<?php

use App\ContentEngine\Drafting\DraftCall;
use App\ContentEngine\Drafting\PageDrafter;
use App\ContentEngine\Generation\PageGenerator;
use App\Enums\ContentStatus;
use App\Jobs\GeneratePage;
use App\Models\ProofItem;
use App\Models\Scopes\SiteScope;
use App\Publishing\RenderCoordinator;
use App\Publishing\RenderOutcome;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\DB;
use Tests\Support\FakeClaudeClient;
use Tests\Support\PageFixture;

function bindPageDrafter(string $response): void
{
    app()->bind(PageDrafter::class, fn () => new PageDrafter(new DraftCall(new FakeClaudeClient($response))));
}

function pageProofId(string $siteId): string
{
    return (string) ProofItem::withoutGlobalScope(SiteScope::class)->where('site_id', $siteId)->value('id');
}

it('queue() stamps the page generating and dispatches without drafting inline', function () {
    Bus::fake();
    $page = PageFixture::intakePage();

    GeneratePage::enqueue($page, actorId: 'op-1');

    expect($page->fresh()->isGenerating())->toBeTrue()
        ->and($page->fresh()->hasDraft())->toBeFalse();

    Bus::assertDispatched(GeneratePage::class, fn (GeneratePage $job) => $job->contentId === $page->id);
});

it('dispatches through a real queue connection without a reserved-name collision', function () {
    config(['queue.default' => 'database']);
    $page = PageFixture::intakePage();

    GeneratePage::enqueue($page);

    expect($page->fresh()->isGenerating())->toBeTrue()
        ->and(DB::table('jobs')->count())->toBe(1);
});

it('handle() drafts + renders the page on the worker → needs_review', function () {
    $page = PageFixture::intakePage();
    $page->markGenerating();

    bindPageDrafter(PageFixture::validResponse(pageProofId($page->site_id)));
    $renders = Mockery::mock(RenderCoordinator::class);
    $renders->shouldReceive('render')->once()->andReturn(new RenderOutcome(new Collection, true, []));
    $this->app->instance(RenderCoordinator::class, $renders);

    (new GeneratePage($page->id))->handle(app(PageGenerator::class));

    $fresh = $page->fresh();
    expect($fresh->status)->toBe(ContentStatus::NeedsReview)
        ->and($fresh->hasDraft())->toBeTrue()
        ->and($fresh->isGenerating())->toBeFalse();
});

it('handle() records the failure and clears generating when the page draft fails', function () {
    $page = PageFixture::intakePage(['status' => ContentStatus::Scored]);
    $page->markGenerating();

    bindPageDrafter(''); // empty draft → failure
    $renders = Mockery::mock(RenderCoordinator::class);
    $renders->shouldReceive('render')->never();
    $this->app->instance(RenderCoordinator::class, $renders);

    (new GeneratePage($page->id))->handle(app(PageGenerator::class));

    $fresh = $page->fresh();
    expect($fresh->status)->toBe(ContentStatus::Scored)
        ->and($fresh->draftError())->not->toBeNull()
        ->and($fresh->isGenerating())->toBeFalse()
        ->and($fresh->generationState())->toBe('failed');
});
