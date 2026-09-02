<?php

namespace App\Models;

use App\Models\Concerns\BelongsToSite;
use App\Reviews\Intake\CompletedJob;
use Database\Factories\ReviewRequestFactory;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * An outbound review solicitation (Review Capture §5/§6). Carries a single-use, expiring token (stored as a
 * hash) and a snapshot of the {@see CompletedJob} `payload`. Site-scoped. At most one live
 * request per (site_id, external_ref) — enforced by a DB partial-unique index so a redelivering upstream can't
 * double-issue. `review_id` is set when the customer submits; reminders bump `reminder_count` (capped at 2).
 *
 * @property array<string, mixed> $payload
 */
class ReviewRequest extends Model
{
    /** @use HasFactory<ReviewRequestFactory> */
    use BelongsToSite, HasFactory, HasUlids;

    protected $guarded = [];

    /** @return BelongsTo<Review, $this> */
    public function review(): BelongsTo
    {
        return $this->belongsTo(Review::class);
    }

    /** @return BelongsTo<Site, $this> */
    public function site(): BelongsTo
    {
        return $this->belongsTo(Site::class);
    }

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'payload' => 'array',
            'sent_at' => 'datetime',
            'opened_at' => 'datetime',
            'submitted_at' => 'datetime',
            'expires_at' => 'datetime',
            'reminder_count' => 'integer',
        ];
    }
}
