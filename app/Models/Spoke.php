<?php

namespace App\Models;

use App\Enums\SpokeGranularity;
use App\Enums\SpokePageType;
use App\Enums\SpokeStatus;
use App\Enums\SpokeTag;
use App\Models\Concerns\BelongsToSite;
use Database\Factories\SpokeFactory;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A spoke in a SiloBlueprint. The silo grouping (blueprint → silos → spokes) is
 * carried on the spoke via `silo` (parent silo/pillar name) + `is_pillar`. The tag
 * places it on the customer problem chain; the status is the owner-confirmed routing.
 *
 * @property string $id
 * @property string $silo_blueprint_id
 * @property string $site_id
 * @property string|null $silo
 * @property bool $is_pillar
 * @property string $name
 * @property SpokePageType $page_type
 * @property SpokeTag $tag
 * @property string|null $head_keyword
 * @property int|null $volume
 * @property SpokeStatus $status
 * @property string|null $connection_note
 * @property SpokeGranularity $granularity
 */
class Spoke extends Model
{
    /** @use HasFactory<SpokeFactory> */
    use BelongsToSite, HasFactory, HasUlids;

    protected $guarded = [];

    /** @return BelongsTo<SiloBlueprint, $this> */
    public function blueprint(): BelongsTo
    {
        return $this->belongsTo(SiloBlueprint::class, 'silo_blueprint_id');
    }

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'is_pillar' => 'boolean',
            'page_type' => SpokePageType::class,
            'tag' => SpokeTag::class,
            'status' => SpokeStatus::class,
            'granularity' => SpokeGranularity::class,
            'volume' => 'integer',
        ];
    }
}
