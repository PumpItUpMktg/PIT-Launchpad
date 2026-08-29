<?php

namespace App\Models;

use App\Models\Concerns\BelongsToSite;
use Database\Factories\CitationFoundDomainFactory;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A result domain the citation scan surfaced for a location (§ Citations), persisted at pull time (the
 * platform's rank-tracking SERPs aren't retained). directory_id set = matched the catalog; null = an
 * unmatched candidate for catalog confirmation (PR5/PR8 harvesting).
 */
class CitationFoundDomain extends Model
{
    /** @use HasFactory<CitationFoundDomainFactory> */
    use BelongsToSite, HasFactory, HasUlids;

    protected $guarded = [];

    /** @return BelongsTo<Location, $this> */
    public function location(): BelongsTo
    {
        return $this->belongsTo(Location::class);
    }

    /** @return BelongsTo<Directory, $this> */
    public function directory(): BelongsTo
    {
        return $this->belongsTo(Directory::class);
    }

    /** True when this domain isn't in the catalog yet — a candidate to confirm. */
    public function isCandidate(): bool
    {
        return $this->directory_id === null;
    }

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'first_seen_at' => 'datetime',
            'last_seen_at' => 'datetime',
        ];
    }
}
