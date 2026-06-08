<?php

namespace App\Models;

use App\Models\Concerns\BelongsToSite;
use Database\Factories\ConversionConfigFactory;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class ConversionConfig extends Model
{
    /** @use HasFactory<ConversionConfigFactory> */
    use BelongsToSite, HasFactory, HasUlids;

    protected $guarded = [];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'primary_actions' => 'array',
            'tracked_numbers' => 'array',
            'lead_destination' => 'array',
            'forms' => 'array',
            'analytics_ids' => 'array',
        ];
    }
}
