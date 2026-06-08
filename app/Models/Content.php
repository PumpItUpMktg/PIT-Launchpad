<?php

namespace App\Models;

use App\Enums\ContentKind;
use App\Enums\ContentStatus;
use App\Enums\IntakeType;
use App\Enums\PageType;
use App\Models\Concerns\BelongsToSite;
use Database\Factories\ContentFactory;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Database\Eloquent\SoftDeletes;

class Content extends Model
{
    /** @use HasFactory<ContentFactory> */
    use BelongsToSite, HasFactory, HasUlids, SoftDeletes;

    protected $guarded = [];

    /** @return BelongsTo<Silo, $this> */
    public function silo(): BelongsTo
    {
        return $this->belongsTo(Silo::class);
    }

    /** @return BelongsTo<Source, $this> */
    public function source(): BelongsTo
    {
        return $this->belongsTo(Source::class);
    }

    /** @return BelongsTo<Keyword, $this> */
    public function targetKeyword(): BelongsTo
    {
        return $this->belongsTo(Keyword::class, 'target_keyword_id');
    }

    /** @return BelongsTo<WireframeKit, $this> */
    public function wireframeKit(): BelongsTo
    {
        return $this->belongsTo(WireframeKit::class);
    }

    /** @return HasMany<ContentVersion, $this> */
    public function versions(): HasMany
    {
        return $this->hasMany(ContentVersion::class);
    }

    /** @return HasOne<SerpSnapshot, $this> */
    public function serpSnapshot(): HasOne
    {
        return $this->hasOne(SerpSnapshot::class);
    }

    /** @return HasMany<RenderJob, $this> */
    public function renderJobs(): HasMany
    {
        return $this->hasMany(RenderJob::class);
    }

    /** @return BelongsToMany<MediaAsset, $this> */
    public function media(): BelongsToMany
    {
        return $this->belongsToMany(MediaAsset::class, 'content_media');
    }

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'kind' => ContentKind::class,
            'page_type' => PageType::class,
            'intake_type' => IntakeType::class,
            'status' => ContentStatus::class,
            'meta' => 'array',
            'schema_payload' => 'array',
            'slot_payload' => 'array',
            'voice_profile_version' => 'integer',
            'wp_post_id' => 'integer',
            'version' => 'integer',
            'published_at' => 'datetime',
        ];
    }
}
