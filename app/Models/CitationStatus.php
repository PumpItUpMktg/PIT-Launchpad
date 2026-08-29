<?php

namespace App\Models;

use App\Enums\CitationLifecycleState;
use App\Enums\CitationPresence;
use App\Enums\CitationSource;
use App\Models\Concerns\BelongsToSite;
use Database\Factories\CitationStatusFactory;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One (location × directory) citation status (§ Citations). Location-scoped. `attributed_location_id` +
 * `attribution_confidence` carry the multi-location safety: a result attributed to a sibling is a
 * sibling_listing / covered_by_sibling and can never become a fix/duplicate/work-order item.
 *
 * @property CitationPresence $presence
 * @property CitationLifecycleState $lifecycle
 * @property CitationSource $source
 * @property bool $covered_by_sibling
 * @property bool $needs_review
 * @property array<string, array{found: mixed, expected: mixed}>|null $mismatch_fields
 * @property string|null $attributed_location_id
 */
class CitationStatus extends Model
{
    /** @use HasFactory<CitationStatusFactory> */
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

    /** @return BelongsTo<Location, $this> */
    public function attributedLocation(): BelongsTo
    {
        return $this->belongsTo(Location::class, 'attributed_location_id');
    }

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'presence' => CitationPresence::class,
            'lifecycle' => CitationLifecycleState::class,
            'source' => CitationSource::class,
            'covered_by_sibling' => 'boolean',
            'needs_review' => 'boolean',
            'mismatch_fields' => 'array',
            'attribution_confidence' => 'integer',
            'verification_cycles' => 'integer',
            'work_order_count' => 'integer',
            'submitted_at' => 'datetime',
            'first_seen_at' => 'datetime',
            'last_scanned_at' => 'datetime',
        ];
    }
}
