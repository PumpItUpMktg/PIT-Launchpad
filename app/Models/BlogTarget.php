<?php

namespace App\Models;

use App\Enums\BlogTargetStatus;
use App\Models\Concerns\BelongsToSite;
use Database\Factories\BlogTargetFactory;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * One entry in a silo's BLOG TARGET QUEUE — an unconsumed informational longtail keyword routed
 * to the blog lane by the prune (supporting + informational ⇒ article target, not a page fold).
 * The directed news-post lane pulls the top queued target; a reactive draft that substantially
 * covers one consumes it too, so the queue never double-assigns. `keyword_id` is UNIQUE — one
 * keyword, one home, structurally.
 *
 * @property string $id
 * @property string $site_id
 * @property string $silo_id
 * @property string $keyword_id
 * @property BlogTargetStatus $status
 * @property string|null $article_ref the consuming article's Content ULID (deferred-FK style)
 * @property Carbon $queued_at
 */
class BlogTarget extends Model
{
    /** @use HasFactory<BlogTargetFactory> */
    use BelongsToSite, HasFactory, HasUlids;

    protected $guarded = [];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'status' => BlogTargetStatus::class,
            'queued_at' => 'datetime',
        ];
    }

    /** @return BelongsTo<Keyword, $this> */
    public function keyword(): BelongsTo
    {
        return $this->belongsTo(Keyword::class);
    }

    /** @return BelongsTo<Silo, $this> */
    public function silo(): BelongsTo
    {
        return $this->belongsTo(Silo::class);
    }
}
