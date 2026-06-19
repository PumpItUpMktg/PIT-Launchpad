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
 * @property string|null $parent_silo_id the parent silo's pillar spoke id when this is a sub-hub
 * @property bool $is_sub_hub this (pillar) silo has been demoted to a sub-hub under its parent
 * @property string|null $primary_keyword the distinct target query for this page (Pass D)
 * @property ArrangementSource|null $keyword_source provenance of the primary keyword (own, not the structural one)
 * @property float|null $keyword_collision_score the cosine behind a detected keyword collision
 * @property bool $flagged an auto-applied judgment call awaiting operator accept/dismiss (blocks Finalize)
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

    /** This (pillar) silo has been demoted to a sub-hub under a parent silo. */
    public function isSubHub(): bool
    {
        return $this->is_pillar && $this->is_sub_hub;
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
            'is_sub_hub' => 'boolean',
            'flagged' => 'boolean',
            'keyword_source' => ArrangementSource::class,
            'keyword_collision_score' => 'float',
            'arrangement_source' => ArrangementSource::class,
            'arrangement_score' => 'float',
            'volume' => 'integer',
            'volume_breakdown' => 'array',
            'volume_at' => 'datetime',
        ];
    }
}
