<?php

use App\Enums\RenderStatus;
use App\Models\RenderJob;
use App\Models\Site;
use Illuminate\Support\Facades\Storage;

function succeededJob(array $attrs = []): RenderJob
{
    $site = Site::factory()->create();

    return RenderJob::factory()->create(array_merge([
        'site_id' => $site->id,
        'slot' => 'hero_image',
        'status' => RenderStatus::Succeeded,
        'r2_key' => "sites/{$site->id}/hero.webp",
        'alt' => 'Technician at work',
        'width' => 1200,
        'height' => 675,
    ], $attrs));
}

it('builds a srcset from variants plus the source as the largest candidate, ascending', function () {
    Storage::fake('r2');
    $job = succeededJob([
        'variants' => [
            400 => 'sites/s/hero-400w.webp',
            800 => 'sites/s/hero-800w.webp',
        ],
    ]);

    $srcset = $job->srcset();
    $base = Storage::disk('r2')->url($job->r2_key);
    $v400 = Storage::disk('r2')->url('sites/s/hero-400w.webp');
    $v800 = Storage::disk('r2')->url('sites/s/hero-800w.webp');

    expect($srcset)->toBe("{$v400} 400w, {$v800} 800w, {$base} 1200w");
    // The source width is the largest candidate.
    expect($srcset)->toEndWith('1200w');
});

it('carries the srcset into the image object', function () {
    Storage::fake('r2');
    $job = succeededJob(['variants' => [400 => 'sites/s/hero-400w.webp']]);

    $object = $job->toImageObject();
    expect($object)->toHaveKey('srcset')
        ->and($object['srcset'])->toContain('400w')
        ->and($object['srcset'])->toContain('1200w');
});

it('emits no srcset when there are no variants (single source image, graceful)', function () {
    Storage::fake('r2');
    $job = succeededJob(['variants' => null]);

    expect($job->srcset())->toBeNull()
        ->and($job->toImageObject())->not->toHaveKey('srcset')
        ->and($job->toImageObject())->toHaveKey('url');
});

it('drops variant widths at or above the source width as redundant', function () {
    Storage::fake('r2');
    // A 1200 "variant" equals the source; a 1600 would upscale — both are dropped, leaving only 400.
    $job = succeededJob([
        'variants' => [
            400 => 'sites/s/hero-400w.webp',
            1200 => 'sites/s/hero-1200w.webp',
            1600 => 'sites/s/hero-1600w.webp',
        ],
    ]);

    $srcset = $job->srcset();
    expect($srcset)->toContain('400w')
        ->and(substr_count($srcset, 'w,') + 1)->toBe(2); // 400w + the 1200w source only
});
