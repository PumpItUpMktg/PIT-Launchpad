<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * One town's ground elevation in feet. Global, keyed on the Census GEOID — see the create migration.
 *
 * @property string $geo_id
 * @property string $name
 * @property string|null $state
 * @property float|null $elevation_ft
 * @property Carbon|null $fetched_at
 */
class TownElevation extends Model
{
    use HasFactory, HasUlids;

    protected $guarded = [];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return ['elevation_ft' => 'float', 'fetched_at' => 'datetime'];
    }
}
