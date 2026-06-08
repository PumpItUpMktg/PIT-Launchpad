<?php

namespace App\Models;

use App\Enums\MediaKind;
use App\Enums\MediaSource;
use App\Models\Concerns\BelongsToSite;
use Database\Factories\MediaAssetFactory;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\SoftDeletes;

class MediaAsset extends Model
{
    /** @use HasFactory<MediaAssetFactory> */
    use BelongsToSite, HasFactory, HasUlids, SoftDeletes;

    protected $guarded = [];

    /** @return BelongsTo<RenderJob, $this> */
    public function renderJob(): BelongsTo
    {
        return $this->belongsTo(RenderJob::class);
    }

    /** @return BelongsToMany<Service, $this> */
    public function services(): BelongsToMany
    {
        return $this->belongsToMany(Service::class, 'media_asset_service');
    }

    /** @return BelongsToMany<Market, $this> */
    public function markets(): BelongsToMany
    {
        return $this->belongsToMany(Market::class, 'media_asset_market');
    }

    /** @return BelongsToMany<Content, $this> */
    public function contents(): BelongsToMany
    {
        return $this->belongsToMany(Content::class, 'content_media');
    }

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'kind' => MediaKind::class,
            'source' => MediaSource::class,
            'service_tags' => 'array',
            'market_tags' => 'array',
            'dimensions' => 'array',
            'rights_ok' => 'boolean',
        ];
    }
}
