<?php

namespace App\Models;

use App\Enums\CitationEventType;
use App\Models\Concerns\BelongsToSite;
use Database\Factories\CitationEventFactory;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * An append-only citation ledger entry (§ Citations, PR4): one transition of a (location × directory) citation.
 * History rows are never updated or deleted — they are the audit trail behind the diff buckets and regression
 * alerts. Location-scoped.
 *
 * The `from_state`/`to_state` columns carry the raw value of whichever axis moved (a presence value for a
 * scan-detected transition, a lifecycle value for a submit/verify/reject) — stored as plain strings since the
 * ledger spans both axes.
 *
 * @property CitationEventType $event_type
 * @property string|null $from_state
 * @property string|null $to_state
 */
class CitationEvent extends Model
{
    /** @use HasFactory<CitationEventFactory> */
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

    /** @return BelongsTo<CitationScanRun, $this> */
    public function scanRun(): BelongsTo
    {
        return $this->belongsTo(CitationScanRun::class, 'citation_scan_run_id');
    }

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'event_type' => CitationEventType::class,
            'occurred_at' => 'datetime',
            'meta' => 'array',
        ];
    }
}
