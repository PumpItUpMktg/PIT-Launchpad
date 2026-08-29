<?php

namespace App\Models;

use Database\Factories\DirectoryMarketSignalFactory;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * The market-dependent rating for a directory in one geography (§ Citations) — whether it ranks locally, its
 * local SERP positions, the competitor count, and a `seo_value_local` that overrides the directory's global
 * SEO value for that market. Global (no tenant scoping); unique per (directory × geo_value).
 *
 * @property bool $ranks_for_local_terms
 * @property list<array{term: string, position: int}>|null $local_serp_positions
 * @property int|null $seo_value_local
 */
class DirectoryMarketSignal extends Model
{
    /** @use HasFactory<DirectoryMarketSignalFactory> */
    use HasFactory, HasUlids;

    protected $guarded = [];

    /** @return BelongsTo<Directory, $this> */
    public function directory(): BelongsTo
    {
        return $this->belongsTo(Directory::class);
    }

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'ranks_for_local_terms' => 'boolean',
            'local_serp_positions' => 'array',
            'competitor_count' => 'integer',
            'seo_value_local' => 'integer',
            'last_evaluated_at' => 'datetime',
        ];
    }
}
