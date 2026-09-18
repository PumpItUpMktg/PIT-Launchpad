<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * One NOAA station's summer humidity normal. Global, keyed on the station — see the create migration.
 *
 * @property string $station_id
 * @property string $name
 * @property string|null $state
 * @property float $lat
 * @property float $lng
 * @property float|null $summer_dew_point_f
 * @property Carbon|null $fetched_at
 */
class ClimateStation extends Model
{
    use HasFactory, HasUlids;

    protected $guarded = [];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'lat' => 'float',
            'lng' => 'float',
            'summer_dew_point_f' => 'float',
            'fetched_at' => 'datetime',
        ];
    }
}
