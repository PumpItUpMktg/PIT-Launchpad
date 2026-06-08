<?php

namespace App\Models;

use App\Enums\CompetitorType;
use App\Models\Concerns\BelongsToSite;
use Database\Factories\CompetitorFactory;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Competitor extends Model
{
    /** @use HasFactory<CompetitorFactory> */
    use BelongsToSite, HasFactory, HasUlids;

    protected $guarded = [];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'type' => CompetitorType::class,
            'market_refs' => 'array',
        ];
    }
}
