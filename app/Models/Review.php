<?php

namespace App\Models;

use App\Enums\ReviewSource;
use App\Enums\ReviewStatus;
use App\Models\Concerns\BelongsToSite;
use Database\Factories\ReviewFactory;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;

/**
 * A captured customer review (Review Capture §5). First-party (solicited) or imported, it moves through the
 * operator approval queue (pending → approved/rejected → published) and, once published, feeds the shipped
 * gated reviews sections via the publish provider. Site-scoped (BelongsToSite); tagged to a Location (null =>
 * needs_location) and up to {@see MAX_SERVICES} Services. `customer_name` is the "First L." display form and
 * `service_address` is internal audit — neither the full name nor anything below city is ever rendered.
 *
 * @property ReviewSource $source
 * @property ReviewStatus $status
 */
class Review extends Model
{
    /** @use HasFactory<ReviewFactory> */
    use BelongsToSite, HasFactory, HasUlids;

    protected $guarded = [];

    /** Services a review may be tagged to (matches Job Capture's job-type cap). */
    public const MAX_SERVICES = 3;

    /** @return BelongsTo<Location, $this> */
    public function location(): BelongsTo
    {
        return $this->belongsTo(Location::class);
    }

    /** @return BelongsToMany<Service, $this> */
    public function services(): BelongsToMany
    {
        return $this->belongsToMany(Service::class, 'review_service')->withTimestamps();
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
            'source' => ReviewSource::class,
            'status' => ReviewStatus::class,
            'rating' => 'integer',
            'needs_location' => 'boolean',
            'reviewed_at' => 'datetime',
            'submitted_at' => 'datetime',
            'approved_at' => 'datetime',
            'published_at' => 'datetime',
        ];
    }
}
