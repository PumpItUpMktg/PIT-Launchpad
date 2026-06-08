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
        ], fn ($v) => $v !== null && $v !== '');
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
        ];
    }
}
