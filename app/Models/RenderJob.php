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

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'status' => RenderStatus::class,
            'timeout' => 'integer',
        ];
    }
}
