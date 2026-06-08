<?php

namespace App\Models;

use App\Enums\ConversionSource;
use App\Enums\ConversionType;
use App\Models\Concerns\BelongsToSite;
use Database\Factories\ConversionFactory;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * A lead/conversion observation — the revenue proxy the §7c client dashboard
 * reports as totals + trends. Populated by the GA4/GHL ingestion seam
 * (mock-first). It carries NO attribution-to-action / ROI field by design.
 *
 * @property ConversionType $type
 * @property ConversionSource $source
 * @property int $count
 * @property Carbon $occurred_at
 */
class Conversion extends Model
{
    /** @use HasFactory<ConversionFactory> */
    use BelongsToSite, HasFactory, HasUlids;

    protected $guarded = [];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'type' => ConversionType::class,
            'source' => ConversionSource::class,
            'count' => 'integer',
            'occurred_at' => 'datetime',
        ];
    }
}
