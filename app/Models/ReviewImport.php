<?php

namespace App\Models;

use App\Models\Concerns\BelongsToSite;
use Database\Factories\ReviewImportFactory;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

/**
 * Progress record for a bulk review import (Review Capture §10). Site-scoped. `skipped_rows` holds the
 * row-numbered report of what didn't import (dedupe hits, invalid required fields) so nothing is silently merged.
 *
 * @property array<int, array{row: int, reason: string}>|null $skipped_rows
 * @property int $imported_count
 * @property int $skipped_count
 * @property int $total_rows
 */
class ReviewImport extends Model
{
    /** @use HasFactory<ReviewImportFactory> */
    use BelongsToSite, HasFactory, HasUlids;

    protected $guarded = [];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'skipped_rows' => 'array',
            'total_rows' => 'integer',
            'imported_count' => 'integer',
            'skipped_count' => 'integer',
        ];
    }
}
