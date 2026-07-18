<?php

namespace App\Publishing;

use App\Enums\RenderStatus;
use App\Integrations\Fal\FalClient;
use App\Integrations\Vision\VisionClient;
use App\Models\RenderJob;
use App\Models\Scopes\SiteScope;
use App\Models\Site;
use Illuminate\Support\Facades\Storage;
use Throwable;

/**
 * Renders one image spec: fal generate → upload to R2 (per-tenant prefix) →
 * Claude vision pass to finalize alt text → mint the SEO metadata. Carries the
 * pilot's scars: bounded retries (no infinite loop), and a `render_failed`
 * terminal state once attempts are exhausted. The fal adapter is already
 * hardened (HTTP timeout, normalized errors); this bounds how often it is tried.
 */
class ImageRenderer
{
    public const MAX_ATTEMPTS = 3;

    public function __construct(
        private readonly FalClient $fal,
        private readonly VisionClient $vision,
        private readonly TenantStorage $storage,
        private readonly ImageVariantGenerator $variants = new ImageVariantGenerator,
    ) {}

    /**
     * Render a job with bounded retries. On success → Succeeded with r2_key +
     * dimensions + verified alt. After MAX_ATTEMPTS failures → RenderFailed
     * (terminal) carrying the last error.
     */
    public function render(RenderJob $job, int $maxAttempts = self::MAX_ATTEMPTS): RenderJob
    {
        $site = Site::withoutGlobalScope(SiteScope::class)->findOrFail($job->site_id);

        for ($attempt = 1; $attempt <= $maxAttempts; $attempt++) {
            $job->attempts = (int) $job->attempts + 1;
            $job->status = RenderStatus::Running;
            $job->save();

            try {
                $image = $this->fal->generate((string) $job->prompt, [
                    'width' => $job->width ?? 1200,
                    'height' => $job->height ?? 675,
                ]);

                $filename = ($job->seo_filename !== null && $job->seo_filename !== '')
                    ? $this->ensureExtension($job->seo_filename, $image->extension())
                    : $job->id.'.'.$image->extension();

                $r2Key = $this->storage->put($site, $filename, $image->bytes);

                // Responsive downscale variants (best-effort): a phone loads a 400/800-wide image
                // instead of the full render. A source too small to downscale, or a GD build without
                // WebP, yields none — the page then serves the single source image (no srcset).
                $variantKeys = [];
                foreach ($this->variants->derive($image->bytes, $image->width) as $width => $bytes) {
                    $variantKeys[$width] = $this->storage->put($site, $this->variantFilename($filename, $width), $bytes);
                }

                $alt = $this->vision->describe(
                    Storage::disk(TenantStorage::DISK)->url($r2Key),
                    $job->alt ?: $job->title,
                );

                $job->fill([
                    'r2_key' => $r2Key,
                    'width' => $image->width,
                    'height' => $image->height,
                    'variants' => $variantKeys !== [] ? $variantKeys : null,
                    'alt' => $alt,
                    'status' => RenderStatus::Succeeded,
                    'error' => null,
                ]);
                $job->save();

                return $job;
            } catch (Throwable $e) {
                // Includes FalException (timeout / normalized provider error).
                $job->error = $e->getMessage();

                if ($attempt >= $maxAttempts) {
                    $job->status = RenderStatus::RenderFailed;
                    $job->save();

                    return $job;
                }

                $job->save();
            }
        }

        return $job;
    }

    /**
     * Ensure the SEO filename carries a real image extension. A spec filename like
     * "{slug}-hero" stored as-is yields an extension-less R2 key, whose public URL
     * is a bare "/sites/{site}/{slug}-hero" — it won't sideload cleanly as a
     * WordPress attachment and reads as a broken og:image. Append the rendered
     * format's extension when one is missing.
     */
    private function ensureExtension(string $filename, string $extension): string
    {
        if ($extension === '' || preg_match('/\.[A-Za-z0-9]{2,4}$/', $filename) === 1) {
            return $filename;
        }

        return $filename.'.'.$extension;
    }

    /**
     * Insert a "-{width}w" marker before the extension so a variant sits beside its source in R2 with
     * an obvious key: "water-heater-hero.webp" → "water-heater-hero-400w.webp". Variants are always
     * re-encoded WebP, so the extension is normalized to .webp regardless of the source filename.
     */
    private function variantFilename(string $filename, int $width): string
    {
        $ext = pathinfo($filename, PATHINFO_EXTENSION);
        $base = $ext !== '' ? substr($filename, 0, -(strlen($ext) + 1)) : $filename;

        return $base.'-'.$width.'w.webp';
    }
}
