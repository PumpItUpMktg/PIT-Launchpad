<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * One town's FEMA flood-zone composition. Global, keyed on the Census GEOID — see the create migration.
 *
 * @property string $geo_id
 * @property string $name
 * @property string|null $state
 * @property bool $mapped
 * @property bool $has_sfha
 * @property list<array{zone: string, sfha: bool, polygons: int}>|null $zones
 * @property Carbon|null $fetched_at
 */
class TownFloodZone extends Model
{
    use HasFactory, HasUlids;

    protected $guarded = [];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return ['zones' => 'array', 'mapped' => 'boolean', 'has_sfha' => 'boolean', 'fetched_at' => 'datetime'];
    }

    /**
     * The Special Flood Hazard Area zone codes mapped in this town, in the order FEMA's own legend reads
     * them (A before AE before V…), de-duplicated.
     *
     * @return list<string>
     */
    public function sfhaZones(): array
    {
        $zones = [];
        foreach ($this->zones ?? [] as $zone) {
            $code = trim($zone['zone']);
            if ($zone['sfha'] && $code !== '') {
                $zones[$code] = true;
            }
        }
        $codes = array_keys($zones);
        sort($codes);

        return $codes;
    }
}
