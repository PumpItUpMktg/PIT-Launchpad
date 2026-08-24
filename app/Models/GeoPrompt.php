<?php

namespace App\Models;

use App\Models\Concerns\BelongsToSite;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

/**
 * An operator-curated GEO test prompt — a question we check AI search engines for the brand's visibility on.
 *
 * @property string $site_id
 * @property string $prompt
 * @property string|null $label
 * @property bool $active
 */
class GeoPrompt extends Model
{
    use BelongsToSite, HasUlids;

    protected $guarded = [];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return ['active' => 'boolean'];
    }

    /** @return HasMany<GeoSnapshot, $this> */
    public function snapshots(): HasMany
    {
        return $this->hasMany(GeoSnapshot::class);
    }

    /** @return HasOne<GeoSnapshot, $this> */
    public function latestSnapshot(): HasOne
    {
        return $this->hasOne(GeoSnapshot::class)->latestOfMany('checked_at');
    }
}
