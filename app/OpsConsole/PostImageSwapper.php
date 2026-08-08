<?php

namespace App\OpsConsole;

use App\Enums\RenderStatus;
use App\Integrations\Vision\VisionClient;
use App\Models\Content;
use App\Models\RenderJob;
use App\Models\Scopes\SiteScope;
use App\Models\Site;
use App\Publishing\ImageVariantGenerator;
use App\Publishing\TenantStorage;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

/**
 * Swaps a post's hero image with an operator-uploaded photo, running the SAME finishing steps the fal
 * render does — minus the AI generation. From the uploaded bytes it: resizes to the hero width, derives
 * the responsive srcset variants ({@see ImageVariantGenerator}), writes an SEO-named file to the tenant's
 * R2 prefix ({@see TenantStorage}), asks Claude vision for alt text ({@see VisionClient}), and stamps it
 * all onto the post's hero {@see RenderJob} (creating one if the post never had an image). Because the
 * publish meta-blob reads the RenderJob → ImageObject, a repush pushes the swapped photo to WordPress
 * with no fal spend.
 */
class PostImageSwapper
{
    /** The hero is normalized down to this width (matching the fal default) when larger. */
    private const HERO_MAX_WIDTH = 1200;

    public function __construct(
        private readonly TenantStorage $storage,
        private readonly VisionClient $vision,
        private readonly ImageVariantGenerator $variants = new ImageVariantGenerator,
    ) {}

    /** Attach an uploaded photo as the post's hero. Returns the updated/created RenderJob. */
    public function swap(Content $post, string $bytes, string $originalExtension): RenderJob
    {
        $site = Site::query()->findOrFail($post->site_id);

        [$bytes, $width, $height, $ext] = $this->normalize($bytes, $originalExtension);

        $filename = $this->seoFilename($post, $ext);
        $r2Key = $this->storage->put($site, $filename, $bytes);

        $variantKeys = [];
        foreach ($this->variants->derive($bytes, $width) as $variantWidth => $variantBytes) {
            $variantKeys[$variantWidth] = $this->storage->put($site, $this->variantFilename($filename, (int) $variantWidth), $variantBytes);
        }

        $alt = $this->vision->describe(
            Storage::disk(TenantStorage::DISK)->url($r2Key),
            (string) ($post->targetKeyword->query ?? $post->title),
        );

        $job = $this->heroJob($post);
        $job->fill([
            'r2_key' => $r2Key,
            'alt' => $alt,
            'title' => (string) $post->title,
            'width' => $width,
            'height' => $height,
            'variants' => $variantKeys !== [] ? $variantKeys : null,
            'seo_filename' => $filename,
            'provider' => 'upload',
            'status' => RenderStatus::Succeeded,
            'attempts' => 0,
            'error' => null,
        ]);
        $job->save();

        return $job;
    }

    /**
     * Resize down to the hero width (re-encoded WebP) when the source is wider and GD+WebP are
     * available; otherwise keep the original bytes. Returns [bytes, width, height, extension].
     *
     * @return array{0: string, 1: int, 2: int, 3: string}
     */
    private function normalize(string $bytes, string $ext): array
    {
        $size = @getimagesizefromstring($bytes);
        $width = is_array($size) ? (int) $size[0] : 0;
        $height = is_array($size) ? (int) $size[1] : 0;

        if ($width > self::HERO_MAX_WIDTH && function_exists('imagecreatefromstring') && function_exists('imagewebp')) {
            $src = @imagecreatefromstring($bytes);
            if ($src !== false) {
                $scaled = imagescale($src, self::HERO_MAX_WIDTH);
                imagedestroy($src);
                if ($scaled !== false) {
                    ob_start();
                    imagewebp($scaled, null, 82);
                    $out = (string) ob_get_clean();
                    imagedestroy($scaled);
                    if ($out !== '') {
                        $newSize = @getimagesizefromstring($out);

                        return [$out, is_array($newSize) ? (int) $newSize[0] : self::HERO_MAX_WIDTH, is_array($newSize) ? (int) $newSize[1] : 0, 'webp'];
                    }
                }
            }
        }

        return [$bytes, $width ?: self::HERO_MAX_WIDTH, $height, $ext !== '' ? $ext : 'jpg'];
    }

    /** SEO filename from the post's slug (else title/id) + the stored extension; TenantStorage slugs it. */
    private function seoFilename(Content $post, string $ext): string
    {
        $base = Str::slug((string) ($post->slug ?: $post->title ?: $post->id));

        return ($base !== '' ? $base : (string) $post->id).'.'.$ext;
    }

    /** Variant key mirrors the render pipeline: "hero.webp" → "hero-400w.webp". */
    private function variantFilename(string $filename, int $width): string
    {
        $ext = pathinfo($filename, PATHINFO_EXTENSION);
        $base = $ext !== '' ? substr($filename, 0, -(strlen($ext) + 1)) : $filename;

        return $base.'-'.$width.'w.webp';
    }

    /** The post's hero RenderJob (prefer a required one), or a fresh one on its hero slot. */
    private function heroJob(Content $post): RenderJob
    {
        $job = RenderJob::withoutGlobalScope(SiteScope::class)
            ->where('content_id', $post->id)
            ->orderByDesc('required')
            ->first();
        if ($job instanceof RenderJob) {
            return $job;
        }

        $specs = is_array($post->meta['image_specs'] ?? null) ? $post->meta['image_specs'] : [];
        $slot = isset($specs[0]['slot']) && is_string($specs[0]['slot']) ? $specs[0]['slot'] : 'hero_image';

        return new RenderJob([
            'site_id' => $post->site_id,
            'content_id' => $post->id,
            'slot' => $slot,
            'required' => false,
            'attempts' => 0,
        ]);
    }
}
