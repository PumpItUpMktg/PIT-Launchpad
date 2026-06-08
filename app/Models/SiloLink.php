<?php

namespace App\Models;

use App\Models\Concerns\BelongsToSite;
use Database\Factories\SiloLinkFactory;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A controlled cross-silo internal link. Within-silo links (pillar<->cluster,
 * siblings) are derivable from the silo tree and not stored as rows.
 */
class SiloLink extends Model
{
    /** @use HasFactory<SiloLinkFactory> */
    use BelongsToSite, HasFactory, HasUlids;

    protected $guarded = [];

    /** @return BelongsTo<Silo, $this> */
    public function fromSilo(): BelongsTo
    {
        return $this->belongsTo(Silo::class, 'from_silo_id');
    }

    /** @return BelongsTo<Silo, $this> */
    public function toSilo(): BelongsTo
    {
        return $this->belongsTo(Silo::class, 'to_silo_id');
    }
}
