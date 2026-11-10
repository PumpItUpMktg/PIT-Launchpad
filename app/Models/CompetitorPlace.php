<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * A map-pack competitor's coordinates, resolved once per place id (global — not tenant-scoped).
 *
 * @property string $place_id
 * @property string|null $name
 * @property float|null $lat
 * @property float|null $lng
 * @property Carbon|null $resolved_at
 * @property Carbon|null $failed_at
 */
class CompetitorPlace extends Model
{
    protected $primaryKey = 'place_id';

    protected $keyType = 'string';

    public $incrementing = false;

    protected $guarded = [];

    protected function casts(): array
    {
        return ['lat' => 'float', 'lng' => 'float', 'resolved_at' => 'datetime', 'failed_at' => 'datetime'];
    }
}
