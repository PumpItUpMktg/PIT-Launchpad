<?php

namespace App\Models;

use App\ContentEngine\Reconcile\PostTownTagger;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A blog post ↔ town tag (§B). Keyed on the normalized town name so it survives coverage/silo rebuilds.
 * Written by {@see PostTownTagger}, read by the location-page local feed and
 * pushed to WordPress as the `lp_area` taxonomy at publish.
 */
class ContentTown extends Model
{
    use HasUlids;

    protected $guarded = [];

    /** @return BelongsTo<Content, $this> */
    public function content(): BelongsTo
    {
        return $this->belongsTo(Content::class);
    }
}
