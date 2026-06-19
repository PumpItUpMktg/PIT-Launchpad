<?php

namespace App\Models;

use App\Enums\ArrangementSource;
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
use Illuminate\Support\Carbon;

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
 * @property array<string, int>|null $volume_breakdown
 * @property Carbon|null $volume_at
 * @property SpokeStatus $status
 * @property string|null $connection_note
 * @property string|null $sibling_brand
 * @property SpokeGranularity $granularity
 * @property string|null $fold_into_id the core spoke this folds into as a section (null = pillar)
 * @property ArrangementSource|null $arrangement_source auto-arrange provenance (null = untouched)
 * @property float|null $arrangement_score the cosine/overlap behind the arrangement decision
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

    /**
     * Still an undecided candidate — no owner routing yet.
     */
    public function isCandidate(): bool
    {
        return $this->status === SpokeStatus::Candidate;
    }

    /**
     * Whether auto-arrange may (re)write this spoke's structure: it must be an undecided
     * candidate (no owner routing yet) AND not a confirmed arrangement (the operator hasn't
     * accepted/dismissed a recommendation on it). Either touch preserves it across re-runs.
     */
    public function isArrangeable(): bool
    {
        return $this->isCandidate() && $this->arrangement_source !== ArrangementSource::Confirmed;
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
            'arrangement_source' => ArrangementSource::class,
            'arrangement_score' => 'float',
            'volume' => 'integer',
            'volume_breakdown' => 'array',
            'volume_at' => 'datetime',
        ];
    }
}
