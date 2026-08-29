<?php

namespace App\Models;

use App\Enums\SharedPhonePurpose;
use App\Models\Concerns\BelongsToSite;
use Database\Factories\TenantSharedPhoneFactory;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A tenant-wide shared phone number (§ Citations) — corporate / emergency / tracking. Shared across many
 * locations by design; carries no attribution signal in the citation scan unless a location owns it as its
 * GBP primary (`owning_location_id`), and even then only with address corroboration. Tenant-scoped.
 *
 * @property SharedPhonePurpose $purpose
 * @property string|null $owning_location_id
 */
class TenantSharedPhone extends Model
{
    /** @use HasFactory<TenantSharedPhoneFactory> */
    use BelongsToSite, HasFactory, HasUlids;

    protected $guarded = [];

    /** @return BelongsTo<Location, $this> */
    public function owningLocation(): BelongsTo
    {
        return $this->belongsTo(Location::class, 'owning_location_id');
    }

    /** True only when a specific location owns this number as its GBP primary (attribution is then possible). */
    public function isOwned(): bool
    {
        return $this->owning_location_id !== null;
    }

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'purpose' => SharedPhonePurpose::class,
        ];
    }
}
