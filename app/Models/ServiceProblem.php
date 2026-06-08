<?php

namespace App\Models;

use Database\Factories\ServiceProblemFactory;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ServiceProblem extends Model
{
    /** @use HasFactory<ServiceProblemFactory> */
    use HasFactory, HasUlids;

    protected $guarded = [];

    /** @return BelongsTo<Service, $this> */
    public function service(): BelongsTo
    {
        return $this->belongsTo(Service::class);
    }
}
