<?php

namespace App\Models;

use App\Enums\RedirectSource;
use App\Models\Concerns\BelongsToSite;
use Database\Factories\RedirectFactory;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Redirect extends Model
{
    /** @use HasFactory<RedirectFactory> */
    use BelongsToSite, HasFactory, HasUlids;

    protected $guarded = [];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'source' => RedirectSource::class,
            'code' => 'integer',
        ];
    }
}
