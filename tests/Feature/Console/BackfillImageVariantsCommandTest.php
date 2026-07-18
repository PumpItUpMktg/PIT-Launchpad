<?php

use App\Enums\RenderStatus;
use App\Models\RenderJob;
use App\Models\Site;
use App\Publishing\ImageVariantGenerator;
use Illuminate\Support\Facades\Storage;

/** A real WebP raster the generator can decode + downscale. */
function storedWebp(string $key, int $w, int $h): void
{
    $im = imagecreatetruecolor($w, $h);
    imagefill($im, 0, 0, imagecolorallocate($im, 20, 130, 90));
    ob_start();
    imagewebp($im, null, 82);
    $bytes = (string) ob_get_clean();
    imagedestroy($im);
    Storage::disk('r2')->put($key, $bytes);
}

it('derives + stores variants for a rendered image that lacks them', function () {
    Storage::fake('r2');
    $site = Site::factory()->create();
    $key = "sites/{$site->id}/hero.webp";
    storedWebp($key, 1200, 675);

    $job = RenderJob::factory()->create([
        'site_id' => $site->id,
        'status' => RenderStatus::Succeeded,
        'r2_key' => $key,
        'width' => 1200,
        'height' => 675,
        'variants' => null,
    ]);

    $this->artisan('launchpad:backfill-image-variants')
        ->expectsOutputToContain('Backfilled variants for 1 image(s)')
        ->assertSuccessful();

    $job->refresh();
    expect(array_keys($job->variants))->toBe(ImageVariantGenerator::WIDTHS);
    foreach ($job->variants as $width => $variantKey) {
        expect($variantKey)->toBe("sites/{$site->id}/hero-{$width}w.webp")
            ->and(Storage::disk('r2')->exists($variantKey))->toBeTrue();
    }
});

it('is idempotent — a job that already has variants is left untouched', function () {
    Storage::fake('r2');
    $site = Site::factory()->create();
    $key = "sites/{$site->id}/hero.webp";
    storedWebp($key, 1200, 675);

    $job = RenderJob::factory()->create([
        'site_id' => $site->id,
        'status' => RenderStatus::Succeeded,
        'r2_key' => $key,
        'width' => 1200,
        'variants' => [400 => 'sites/x/pre-existing.webp'],
    ]);

    $this->artisan('launchpad:backfill-image-variants')
        ->expectsOutputToContain('Backfilled variants for 0 image(s)')
        ->assertSuccessful();

    $job->refresh();
    expect($job->variants)->toBe([400 => 'sites/x/pre-existing.webp']);
});

it('can be scoped to a single site', function () {
    Storage::fake('r2');
    $a = Site::factory()->create();
    $b = Site::factory()->create();
    foreach ([$a, $b] as $site) {
        storedWebp("sites/{$site->id}/hero.webp", 1200, 675);
        RenderJob::factory()->create([
            'site_id' => $site->id,
            'status' => RenderStatus::Succeeded,
            'r2_key' => "sites/{$site->id}/hero.webp",
            'width' => 1200,
            'variants' => null,
        ]);
    }

    $this->artisan('launchpad:backfill-image-variants', ['--site' => $a->id])
        ->assertSuccessful();

    expect(RenderJob::withoutGlobalScopes()->where('site_id', $a->id)->first()->variants)->not->toBeNull()
        ->and(RenderJob::withoutGlobalScopes()->where('site_id', $b->id)->first()->variants)->toBeNull();
});
