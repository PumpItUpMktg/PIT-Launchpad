<?php

namespace App\Models;

use App\Enums\PageType;
use App\PageBuilder\Schema\KitSchema;
use Database\Factories\WireframeKitFactory;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * The content contract: slot definitions and constraints. May be library-level
 * (null site_id, shared) or per-site, so it deliberately opts out of the
 * BelongsToSite global scope.
 */
class WireframeKit extends Model
{
    /** @use HasFactory<WireframeKitFactory> */
    use HasFactory, HasUlids;

    protected $guarded = [];

    /** @return BelongsTo<Site, $this> */
    public function site(): BelongsTo
    {
        return $this->belongsTo(Site::class);
    }

    /** @return HasMany<Content, $this> */
    public function contents(): HasMany
    {
        return $this->hasMany(Content::class);
    }

    /**
     * The typed slot-schema for this kit, parsed from the slot_schema column.
     */
    public function schema(): KitSchema
    {
        return KitSchema::fromArray($this->slot_schema ?? []);
    }

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'slot_schema' => 'array',
            'page_type' => PageType::class,
            'version' => 'integer',
        ];
    }
}
