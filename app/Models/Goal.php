<?php

namespace App\Models;

use App\Models\Concerns\BelongsToSite;
use Database\Factories\GoalFactory;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Goal extends Model
{
    /** @use HasFactory<GoalFactory> */
    use BelongsToSite, HasFactory, HasUlids;

    protected $guarded = [];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'target' => 'decimal:2',
        ];
    }
}
