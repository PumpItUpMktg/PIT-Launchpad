<?php

namespace App\Models;

use Database\Factories\SerpSnapshotFactory;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class SerpSnapshot extends Model
{
    /** @use HasFactory<SerpSnapshotFactory> */
    use HasFactory, HasUlids;

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
            'captured_at' => 'datetime',
            'competitor_analysis' => 'array',
            'diff' => 'array',
        ];
    }
}
