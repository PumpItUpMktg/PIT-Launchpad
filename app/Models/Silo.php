<?php

namespace App\Models;

use App\Enums\SiloType;
use App\Models\Concerns\BelongsToSite;
use Database\Factories\SiloFactory;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

class Silo extends Model
{
    /** @use HasFactory<SiloFactory> */
    use BelongsToSite, HasFactory, HasUlids, SoftDeletes;

    protected $guarded = [];

    /** @return BelongsTo<Silo, $this> */
    public function parent(): BelongsTo
    {
        return $this->belongsTo(Silo::class, 'parent_silo_id');
    }

    /** @return HasMany<Silo, $this> */
    public function children(): HasMany
    {
        return $this->hasMany(Silo::class, 'parent_silo_id');
    }

    /** @return BelongsToMany<Service, $this> */
    public function services(): BelongsToMany
    {
        return $this->belongsToMany(Service::class, 'silo_service');
    }

    /** @return HasMany<Keyword, $this> */
    public function keywords(): HasMany
    {
        return $this->hasMany(Keyword::class);
    }

    /** @return HasMany<Content, $this> */
    public function contents(): HasMany
    {
        return $this->hasMany(Content::class);
    }

    /** @return HasMany<Source, $this> */
    public function sources(): HasMany
    {
        return $this->hasMany(Source::class);
    }

    /**
     * The pillar page for this silo. FK is intentionally not enforced at the
     * database level due to the Silo <-> Content circular dependency.
     *
     * @return BelongsTo<Content, $this>
     */
    public function pillarContent(): BelongsTo
    {
        return $this->belongsTo(Content::class, 'pillar_content_id');
    }

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'type' => SiloType::class,
            'rule_set' => 'array',
            'wp_category_id' => 'integer',
        ];
    }
}
