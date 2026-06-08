<?php

namespace App\Models;

use App\Enums\SourceDocType;
use App\Models\Concerns\BelongsToSite;
use Database\Factories\SourceDocumentFactory;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class SourceDocument extends Model
{
    /** @use HasFactory<SourceDocumentFactory> */
    use BelongsToSite, HasFactory, HasUlids;

    protected $guarded = [];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'type' => SourceDocType::class,
            'grounding_enabled' => 'boolean',
        ];
    }
}
