<?php

namespace App\Models;

use App\Enums\RenderStatus;
use App\Models\Concerns\BelongsToSite;
use Database\Factories\RenderJobFactory;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Facades\Storage;

/**
 * @property RenderStatus $status
 * @property string|null $slot
 * @property string|null $r2_key
 * @property string|null $alt
 * @property string|null $title
 * @property string|null $caption
 * @property bool $required
 * @property int $attempts
 * @property int|null $width
 * @property int|null $height
 * @property array<int|string, string>|null $variants
 */
class RenderJob extends Model
{
    /** @use HasFactory<RenderJobFactory> */
    use BelongsToSite, HasFactory, HasUlids;

    protected $guarded = [];

    /** @return BelongsTo<Content, $this> */
    public function content(): BelongsTo
    {
        return $this->belongsTo(Content::class);
    }

    /** @return HasMany<MediaAsset, $this> */
    public function mediaAssets(): HasMany
    {
        return $this->hasMany(MediaAsset::class);
    }

    public function isRendered(): bool
    {
        return $this->status === RenderStatus::Succeeded;
    }

    public function hasFailed(): bool
    {
        return $this->status === RenderStatus::RenderFailed;
    }

    /**
     * The image-object entry this render contributes to a /content payload's
     * `images` map (keyed by slot). Null until the render has succeeded.
     *
     * @return array<string, mixed>|null
     */
    public function toImageObject(): ?array
    {
        if (! $this->isRendered() || $this->r2_key === null) {
            return null;
        }

        return array_filter([
            'url' => Storage::disk('r2')->url($this->r2_key),
            'alt' => $this->alt,
            'title' => $this->title,
            'caption' => $this->caption,
            'width' => $this->width,
            'height' => $this->height,
            'srcset' => $this->srcset(),
        ], fn ($v) => $v !== null && $v !== '');
    }

    /**
     * A responsive `srcset` string built from the derived variants plus the source render as the
     * largest candidate — e.g. "https://cdn/…-400w.webp 400w, https://cdn/…-800w.webp 800w,
     * https://cdn/…hero.webp 1200w". Null when no variants were derived (the page then serves the
     * single source `url` with no srcset). Descending duplicates and widths at/above the source
     * would be redundant, so the source width always caps the list.
     */
    public function srcset(): ?string
    {
        $variants = is_array($this->variants) ? $this->variants : [];
        if ($variants === [] || $this->r2_key === null || ! $this->width) {
            return null;
        }

        $disk = Storage::disk('r2');
        $candidates = [];
        foreach ($variants as $width => $key) {
            $w = (int) $width;
            if ($w > 0 && $w < $this->width && $key !== '') {
                $candidates[$w] = $disk->url($key).' '.$w.'w';
            }
        }
        if ($candidates === []) {
            return null;
        }
        $candidates[$this->width] = $disk->url($this->r2_key).' '.$this->width.'w';
        ksort($candidates);

        return implode(', ', $candidates);
    }

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'status' => RenderStatus::class,
            'timeout' => 'integer',
            'required' => 'boolean',
            'attempts' => 'integer',
            'width' => 'integer',
            'height' => 'integer',
            'variants' => 'array',
        ];
    }
}
