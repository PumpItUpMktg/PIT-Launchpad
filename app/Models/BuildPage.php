<?php

namespace App\Models;

use App\Enums\BuildSource;
use App\Enums\BuildStatus;
use App\Models\Concerns\BelongsToSite;
use Database\Factories\BuildPageFactory;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One page in a site's build manifest — its source, content recipe, lifecycle status, build
 * priority, and review requirement. Tenant-scoped ({@see BelongsToSite}): the context-aware
 * SiteScope filters to the locked tenant in the panel and is a no-op in the cross-tenant lobby.
 *
 * @property string $id
 * @property string $site_id
 * @property BuildSource $source
 * @property string $page_key
 * @property string $title
 * @property string $recipe
 * @property BuildStatus $status
 * @property int $priority
 * @property bool $review_required
 * @property string|null $spoke_id
 * @property string|null $content_id the materialized Content row (set at Approve)
 */
class BuildPage extends Model
{
    /** @use HasFactory<BuildPageFactory> */
    use BelongsToSite, HasFactory, HasUlids;

    protected $guarded = [];

    /** @return BelongsTo<Content, $this> */
    public function content(): BelongsTo
    {
        return $this->belongsTo(Content::class);
    }

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'source' => BuildSource::class,
            'status' => BuildStatus::class,
            'priority' => 'integer',
            'review_required' => 'boolean',
        ];
    }
}
