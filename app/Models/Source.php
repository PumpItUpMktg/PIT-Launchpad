<?php

namespace App\Models;

use App\Enums\SourceType;
use App\Models\Concerns\BelongsToSite;
use Database\Factories\SourceFactory;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Source extends Model
{
    /** @use HasFactory<SourceFactory> */
    use BelongsToSite, HasFactory, HasUlids;

    protected $guarded = [];

    /** @return BelongsTo<Silo, $this> */
    public function silo(): BelongsTo
    {
        return $this->belongsTo(Silo::class);
    }

    /** @return HasMany<Content, $this> */
    public function contents(): HasMany
    {
        return $this->hasMany(Content::class);
    }

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'type' => SourceType::class,
            'config' => 'array',
        ];
    }
}
