<?php

use App\Enums\ContentStatus;
use App\Models\Content;
use App\Models\Site;
use App\Publishing\PagePreviewService;
use App\Publishing\PreviewResult;
use App\Publishing\SitePreviewService;

it('previews every page of a site and aggregates each per-page result', function () {
    $site = Site::factory()->create();
    Content::factory()->page()->create(['site_id' => $site->id, 'slug' => 'home', 'title' => 'Home', 'slot_payload' => ['hero' => 'x']]);
    Content::factory()->page()->create(['site_id' => $site->id, 'slug' => 'about', 'title' => 'About', 'slot_payload' => ['hero' => 'y']]);
    // A page from ANOTHER site must not be previewed here (tenant isolation).
    Content::factory()->page()->create(['site_id' => Site::factory()->create()->id, 'slug' => 'foreign', 'slot_payload' => ['hero' => 'z']]);

    $pagePreview = Mockery::mock(PagePreviewService::class);
    $pagePreview->shouldReceive('preview')->twice()->andReturn(PreviewResult::ready(101, 'https://wp.example/?p=101&preview=true'));

    $results = (new SitePreviewService($pagePreview))->previewSite($site);

    expect($results)->toHaveCount(2)
        ->and(collect($results)->pluck('slug')->sort()->values()->all())->toBe(['about', 'home'])
        ->and($results[0]['result']->isReady())->toBeTrue()
        ->and($results[0]['result']->previewUrl)->toBe('https://wp.example/?p=101&preview=true');
});

it('carries through the per-page skip (an undrafted page is reported unavailable, not pushed)', function () {
    $site = Site::factory()->create();
    Content::factory()->page()->create(['site_id' => $site->id, 'slug' => 'contact', 'title' => 'Contact', 'slot_payload' => []]);

    // The real PagePreviewService returns `unavailable` for an undrafted page — mirror that here.
    $pagePreview = Mockery::mock(PagePreviewService::class);
    $pagePreview->shouldReceive('preview')->once()->andReturn(PreviewResult::unavailable('Generate the page first.'));

    $results = (new SitePreviewService($pagePreview))->previewSite($site);

    expect($results)->toHaveCount(1)
        ->and($results[0]['result']->state)->toBe('unavailable')
        ->and($results[0]['result']->isReady())->toBeFalse();
});

it('never flips Content.status — a bulk preview keeps every page exactly where it was', function () {
    $site = Site::factory()->create();
    $page = Content::factory()->page()->create(['site_id' => $site->id, 'slug' => 'home', 'status' => ContentStatus::NeedsReview, 'slot_payload' => ['hero' => 'x']]);

    $pagePreview = Mockery::mock(PagePreviewService::class);
    $pagePreview->shouldReceive('preview')->once()->andReturn(PreviewResult::ready(1, 'https://wp.example/?preview=true'));

    (new SitePreviewService($pagePreview))->previewSite($site);

    expect($page->fresh()->status)->toBe(ContentStatus::NeedsReview); // untouched — nothing goes live
});

it('the command previews a site by brand name and reports internal-only drafts', function () {
    $site = Site::factory()->create(['brand_name' => 'Sewer Gurus']);
    Content::factory()->page()->create(['site_id' => $site->id, 'slug' => 'home', 'title' => 'Home', 'slot_payload' => ['hero' => 'x']]);

    $pagePreview = Mockery::mock(PagePreviewService::class);
    $pagePreview->shouldReceive('preview')->once()->andReturn(PreviewResult::ready(1, 'https://wp.example/?p=1&preview=true'));
    app()->instance(PagePreviewService::class, $pagePreview);

    $this->artisan('launchpad:preview-site', ['site' => 'Sewer Gurus'])
        ->expectsOutputToContain('with all sections shown')
        ->expectsOutputToContain('never to visitors')
        ->assertExitCode(0);
});
