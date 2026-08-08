<?php

use App\Enums\RenderStatus;
use App\Filament\Console\Concerns\SwapsPostImage;
use App\Filament\Console\Pages\BlogPublish;
use App\Filament\Console\Pages\BlogReview;
use App\Filament\Console\Pages\Published;
use App\Integrations\Vision\MockVisionClient;
use App\Integrations\Vision\VisionClient;
use App\Models\Content;
use App\Models\RenderJob;
use App\Models\Scopes\SiteScope;
use App\Models\Site;
use App\OpsConsole\PostImageSwapper;
use Illuminate\Support\Facades\Storage;

function jpegBytes(int $w, int $h): string
{
    $img = imagecreatetruecolor($w, $h);
    ob_start();
    imagejpeg($img);
    $bytes = (string) ob_get_clean();
    imagedestroy($img);

    return $bytes;
}

it('swaps a post image: stores to R2, alt-texts, and attaches a succeeded render job', function () {
    Storage::fake('r2');
    app()->bind(VisionClient::class, MockVisionClient::class);

    $site = Site::factory()->create();
    $post = Content::factory()->post()->create(['site_id' => $site->id, 'title' => 'Sump Pump Guide', 'slug' => 'sump-pump-guide']);

    $job = app(PostImageSwapper::class)->swap($post, jpegBytes(1600, 900), 'jpg');

    expect($job->status)->toBe(RenderStatus::Succeeded)
        ->and($job->r2_key)->not->toBeNull()
        ->and((string) $job->alt)->not->toBe('')
        ->and($job->content_id)->toBe($post->id)
        ->and($job->provider)->toBe('upload');
    Storage::disk('r2')->assertExists($job->r2_key);

    // When GD can encode WebP, the source is resized to the hero width and SEO-renamed from the slug.
    if (function_exists('imagewebp')) {
        expect($job->width)->toBe(1200)
            ->and($job->seo_filename)->toBe('sump-pump-guide.webp');
    }
});

it('updates the post\'s existing render job rather than adding a second', function () {
    Storage::fake('r2');
    app()->bind(VisionClient::class, MockVisionClient::class);

    $site = Site::factory()->create();
    $post = Content::factory()->post()->create(['site_id' => $site->id, 'title' => 'Guide', 'slug' => 'guide']);
    RenderJob::create(['site_id' => $site->id, 'content_id' => $post->id, 'slot' => 'hero_image', 'status' => RenderStatus::Succeeded, 'required' => true, 'r2_key' => 'old/key.webp']);

    app(PostImageSwapper::class)->swap($post, jpegBytes(800, 450), 'jpg');

    $jobs = RenderJob::withoutGlobalScope(SiteScope::class)->where('content_id', $post->id)->get();
    expect($jobs)->toHaveCount(1)
        ->and($jobs->first()->r2_key)->not->toBe('old/key.webp');
});

it('wires the swap-photo trait onto the review, publish and published pages', function () {
    foreach ([BlogReview::class, BlogPublish::class, Published::class] as $page) {
        expect(in_array(SwapsPostImage::class, class_uses_recursive($page), true))->toBeTrue();
    }
});
