<?php

namespace App\Models;

use App\Enums\BuildSource;
use App\Enums\BuildStatus;
use Database\Factories\BuildPageFactory;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One page in a site's build manifest — its source, content recipe, lifecycle status, build
 * priority, and review requirement. Read across tenants by the operator, so no site scope
 * (keyed explicitly by site).
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
 */
class BuildPage extends Model
{
    /** @use HasFactory<BuildPageFactory> */
    use HasFactory, HasUlids;

    protected $guarded = [];

    /** @return BelongsTo<Site, $this> */
    public function site(): BelongsTo
    {
        return $this->belongsTo(Site::class);
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
