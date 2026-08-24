<?php

namespace App\Models;

use App\Models\Concerns\BelongsToSite;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * Durable per-URL Core Web Vitals reading (the "Site speed" slice), upserted by the PageSpeed audit and
 * keyed on url_normalized — the sibling of {@see PageIndexState}. Holds the latest measurement per URL,
 * not a time-series.
 *
 * @property string $site_id
 * @property string|null $content_id
 * @property string $url
 * @property string $url_normalized
 * @property string $strategy
 * @property int|null $performance_score
 * @property int|null $lcp_ms
 * @property float|null $cls
 * @property int|null $inp_ms
 * @property Carbon|null $measured_at
 */
class PageVital extends Model
{
    use BelongsToSite, HasUlids;

    protected $guarded = [];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'performance_score' => 'integer',
            'lcp_ms' => 'integer',
            'cls' => 'decimal:3',
            'inp_ms' => 'integer',
            'measured_at' => 'datetime',
        ];
    }

    /** "Good" on Google's CWV thresholds: LCP ≤ 2.5s, CLS ≤ 0.1, INP ≤ 200ms (INP only when measured). */
    public function passesCoreWebVitals(): bool
    {
        if ($this->lcp_ms === null || $this->cls === null) {
            return false;
        }

        return $this->lcp_ms <= 2500 && (float) $this->cls <= 0.1 && ($this->inp_ms === null || $this->inp_ms <= 200);
    }

    /** @return BelongsTo<Content, $this> */
    public function content(): BelongsTo
    {
        return $this->belongsTo(Content::class);
    }
}
