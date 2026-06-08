<?php

namespace App\Models;

use App\Enums\ConnectionProvider;
use App\Models\Concerns\BelongsToSite;
use Database\Factories\ConnectionFactory;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Connection extends Model
{
    /** @use HasFactory<ConnectionFactory> */
    use BelongsToSite, HasFactory, HasUlids;

    protected $guarded = [];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'provider' => ConnectionProvider::class,
            'credentials' => 'encrypted:array',
            'scopes' => 'array',
            'last_rotated_at' => 'datetime',
        ];
    }
}
