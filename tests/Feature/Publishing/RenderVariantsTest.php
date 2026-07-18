<?php

use App\Enums\RenderStatus;
use App\Integrations\Fal\MockFalClient;
use App\Integrations\Vision\MockVisionClient;
use App\Models\RenderJob;
use App\Models\Site;
use App\Publishing\ImageRenderer;
use App\Publishing\ImageVariantGenerator;
use App\Publishing\TenantStorage;
use Illuminate\Support\Facades\Storage;

it('stores downscale variants beside the source render and records their R2 keys', function () {
    Storage::fake('r2');
    $site = Site::factory()->create();
    $job = RenderJob::factory()->create([
        'site_id' => $site->id,
        'slot' => 'hero_image',
        'seo_filename' => 'hero.webp',
        'alt' => 'Technician at work',
    ]);

    (new ImageRenderer(new MockFalClient, new MockVisionClient, new TenantStorage))->render($job);
    $job->refresh();

    expect($job->status)->toBe(RenderStatus::Succeeded)
        ->and($job->variants)->toBeArray()
        ->and(array_keys($job->variants))->toBe(ImageVariantGenerator::WIDTHS); // [400, 800]

    // Each variant is a real object in R2 at a "-{w}w.webp" key beside the source.
    foreach ($job->variants as $width => $key) {
        expect($key)->toBe("sites/{$site->id}/hero-{$width}w.webp")
            ->and(Storage::disk('r2')->exists($key))->toBeTrue();
    }
    // The source render is still there and is the largest srcset candidate.
    expect(Storage::disk('r2')->exists($job->r2_key))->toBeTrue()
        ->and($job->toImageObject()['srcset'])->toContain('400w')->toContain('1200w');
});

it('degrades to no variants (single source image) when generation yields none', function () {
    Storage::fake('r2');
    $site = Site::factory()->create();
    $job = RenderJob::factory()->create([
        'site_id' => $site->id,
        'slot' => 'hero_image',
        'seo_filename' => 'hero.webp',
    ]);

    // A generator that derives nothing (e.g. a GD build without WebP, or a source too small).
    $noVariants = new class extends ImageVariantGenerator
    {
        public function derive(string $bytes, int $sourceWidth): array
        {
            return [];
        }
    };

    (new ImageRenderer(new MockFalClient, new MockVisionClient, new TenantStorage, $noVariants))->render($job);
    $job->refresh();

    expect($job->status)->toBe(RenderStatus::Succeeded)
        ->and($job->variants)->toBeNull()
        ->and($job->toImageObject())->not->toHaveKey('srcset')
        ->and($job->toImageObject())->toHaveKey('url');
});
