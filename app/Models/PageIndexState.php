<?php

namespace App\Models;

use App\Models\Concerns\BelongsToSite;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * Durable per-URL Google index state (§ Client Dashboard v1) — the trendable home for what has until now
 * lived only in the cache. Upserted by the URL Inspection sync, keyed on url_normalized.
 *
 * @property string $site_id
 * @property string|null $content_id
 * @property string $url
 * @property string $url_normalized
 * @property string|null $coverage_state
 * @property string|null $index_verdict
 * @property string|null $robots_state
 * @property string|null $canonical_url
 * @property Carbon|null $last_crawled_at
 * @property Carbon|null $last_inspected_at
 */
class PageIndexState extends Model
{
    use BelongsToSite, HasUlids;

    protected $guarded = [];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'last_crawled_at' => 'datetime',
            'last_inspected_at' => 'datetime',
        ];
    }

    /** Google reports this URL as indexed. */
    public function isIndexed(): bool
    {
        return $this->index_verdict === 'PASS';
    }

    /** @return BelongsTo<Content, $this> */
    public function content(): BelongsTo
    {
        return $this->belongsTo(Content::class);
    }
}
