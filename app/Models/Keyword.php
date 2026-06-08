<?php

namespace App\Models;

use App\Enums\KeywordSource;
use App\Models\Concerns\BelongsToSite;
use Database\Factories\KeywordFactory;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Keyword extends Model
{
    /** @use HasFactory<KeywordFactory> */
    use BelongsToSite, HasFactory, HasUlids;

    protected $guarded = [];

    /** @return BelongsTo<Silo, $this> */
    public function silo(): BelongsTo
    {
        return $this->belongsTo(Silo::class);
    }

    /**
     * Content rows that target this keyword.
     *
     * @return HasMany<Content, $this>
     */
    public function contents(): HasMany
    {
        return $this->hasMany(Content::class, 'target_keyword_id');
    }

    /**
     * The content this keyword resolves to. FK is intentionally not enforced at
     * the database level due to the Keyword <-> Content circular dependency.
     *
     * @return BelongsTo<Content, $this>
     */
    public function targetContent(): BelongsTo
    {
        return $this->belongsTo(Content::class, 'target_content_id');
    }

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'source' => KeywordSource::class,
            'volume' => 'integer',
            'difficulty' => 'integer',
            'opportunity_score' => 'decimal:4',
            'beatability' => 'decimal:4',
        ];
    }
}
