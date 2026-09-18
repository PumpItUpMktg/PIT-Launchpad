<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * One town's soil drainage, area-weighted. Global, keyed on the Census GEOID — see the create migration.
 *
 * @property string $geo_id
 * @property string $name
 * @property string|null $state
 * @property bool $surveyed
 * @property string|null $dominant
 * @property float|null $poorly_share
 * @property float|null $water_share share of the town's mapped area that is seabed, not ground
 * @property list<array{class: string, share: float}>|null $classes
 * @property Carbon|null $fetched_at
 */
class TownSoilDrainage extends Model
{
    use HasFactory, HasUlids;

    protected $table = 'town_soil_drainage';

    protected $guarded = [];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'classes' => 'array',
            'surveyed' => 'boolean',
            'poorly_share' => 'float',
            'water_share' => 'float',
            'fetched_at' => 'datetime',
        ];
    }
}
