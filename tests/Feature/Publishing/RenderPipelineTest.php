<?php

use App\Enums\ContentStatus;
use App\Enums\RenderStatus;
use App\Integrations\Fal\FalClient;
use App\Integrations\Fal\MockFalClient;
use App\Integrations\Vision\MockVisionClient;
use App\Integrations\Vision\VisionClient;
use App\Jobs\RenderImage;
use App\Models\Content;
use App\Models\RenderJob;
use App\Models\Site;
use App\Publishing\ImageRenderer;
use App\Publishing\PublishContentService;
use App\Publishing\TenantStorage;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Tests\Support\PublishHarness;
use Tests\Support\ThrowingFalClient;

test('a render mints an R2 object plus alt text from the vision pass', function () {
    Storage::fake('r2');
    $site = Site::factory()->create();
    $job = RenderJob::factory()->create([
        'site_id' => $site->id,
        'slot' => 'hero_image',
        'seo_filename' => 'hero.webp',
        'alt' => 'Technician at work',
    ]);

    $renderer = new ImageRenderer(new MockFalClient, new MockVisionClient, new TenantStorage);
    $renderer->render($job);

    $job->refresh();
    expect($job->status)->toBe(RenderStatus::Succeeded)
        ->and($job->r2_key)->toBe("sites/{$site->id}/hero.webp")
        ->and(Storage::disk('r2')->exists($job->r2_key))->toBeTrue()
        ->and($job->alt)->toBe('Technician at work')
        ->and($job->width)->toBe(1200);

    expect($job->toImageObject())->toHaveKey('url');
});

test('a render that keeps failing reaches the render_failed terminal after bounded retries', function () {
    Storage::fake('r2');
    $site = Site::factory()->create();
    $job = RenderJob::factory()->create(['site_id' => $site->id]);

    $fal = new ThrowingFalClient;
    (new ImageRenderer($fal, new MockVisionClient, new TenantStorage))->render($job, maxAttempts: 3);

    $job->refresh();
    expect($job->status)->toBe(RenderStatus::RenderFailed)
        ->and($job->attempts)->toBe(3)
        ->and($fal->calls)->toBe(3)
        ->and($job->error)->toContain('fal');
});

test('a required image that will not render blocks the publish (no partial page)', function () {
    Storage::fake('r2');
    Http::fake();
    app()->bind(FalClient::class, ThrowingFalClient::class);
    app()->bind(VisionClient::class, MockVisionClient::class);

    $site = PublishHarness::site();
    $content = PublishHarness::approvedPage($site);

    $result = app(PublishContentService::class)->publish($content);

    expect($result->isBlocked())->toBeTrue()
        ->and($content->fresh()->status)->toBe(ContentStatus::RenderFailed)
        ->and($content->fresh()->last_publish_error)->toContain('hero_image');

    // Nothing was pushed to WordPress.
    Http::assertNothingSent();
});

test('the reset-render command requeues render_failed jobs', function () {
    Bus::fake();
    $site = Site::factory()->create();
    $content = Content::factory()->create(['site_id' => $site->id]);
    $job = RenderJob::factory()->failed()->create(['site_id' => $site->id, 'content_id' => $content->id]);

    $this->artisan('launchpad:reset-render', ['content' => $content->id])->assertSuccessful();

    expect($job->fresh()->status)->toBe(RenderStatus::Queued)
        ->and($job->fresh()->attempts)->toBe(0);

    Bus::assertDispatched(RenderImage::class);
});
