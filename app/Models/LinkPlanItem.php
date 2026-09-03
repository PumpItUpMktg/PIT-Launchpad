<?php

namespace App\Models;

use App\Enums\LinkPlanItemStatus;
use App\Enums\LinkSourceType;
use App\Models\Concerns\BelongsToSite;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * One proposed inbound link within a {@see LinkPlan}: `source_content` → `target_content` (a newly-built
 * town page), proposed by `source_type` (one of the five sources), applied by wrapping `anchor_term` in the
 * source (or an appended "Related:" link / a whole-page republish for the Areas source) and re-publishing.
 *
 * @property string $id
 * @property string $link_plan_id
 * @property string $site_id
 * @property string|null $source_content_id
 * @property string $target_content_id
 * @property LinkSourceType $source_type
 * @property string|null $anchor_term
 * @property LinkPlanItemStatus $status
 * @property Carbon|null $applied_at
 */
class LinkPlanItem extends Model
{
    use BelongsToSite, HasFactory, HasUlids;

    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'source_type' => LinkSourceType::class,
            'status' => LinkPlanItemStatus::class,
            'applied_at' => 'datetime',
        ];
    }

    /** @return BelongsTo<LinkPlan, $this> */
    public function plan(): BelongsTo
    {
        return $this->belongsTo(LinkPlan::class, 'link_plan_id');
    }

    /** @return BelongsTo<Content, $this> */
    public function source(): BelongsTo
    {
        return $this->belongsTo(Content::class, 'source_content_id');
    }

    /** @return BelongsTo<Content, $this> */
    public function target(): BelongsTo
    {
        return $this->belongsTo(Content::class, 'target_content_id');
    }
}
