<?php

namespace App\Models;

use App\Enums\LinkPlanStatus;
use App\Models\Concerns\BelongsToSite;
use App\Publishing\Links\LinkPlanCommitter;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * A proposed set of internal links for a market's newly-unlocked tier — the "link plan on unlock". Proposed
 * from the five sources, reviewed by the operator, then committed (links written + IndexNow) by
 * {@see LinkPlanCommitter}. Never auto-applied.
 *
 * @property string $id
 * @property string $site_id
 * @property string|null $market_location_id
 * @property string|null $tier
 * @property LinkPlanStatus $status
 */
class LinkPlan extends Model
{
    use BelongsToSite, HasFactory, HasUlids;

    protected $guarded = [];

    protected function casts(): array
    {
        return ['status' => LinkPlanStatus::class];
    }

    /** @return HasMany<LinkPlanItem, $this> */
    public function items(): HasMany
    {
        return $this->hasMany(LinkPlanItem::class);
    }

    /** @return BelongsTo<Location, $this> */
    public function marketLocation(): BelongsTo
    {
        return $this->belongsTo(Location::class, 'market_location_id');
    }
}
