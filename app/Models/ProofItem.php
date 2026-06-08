<?php

namespace App\Models;

use App\Enums\ProofType;
use App\Models\Concerns\BelongsToSite;
use Database\Factories\ProofItemFactory;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\SoftDeletes;

class ProofItem extends Model
{
    /** @use HasFactory<ProofItemFactory> */
    use BelongsToSite, HasFactory, HasUlids, SoftDeletes;

    protected $guarded = [];

    /** @return BelongsToMany<Service, $this> */
    public function services(): BelongsToMany
    {
        return $this->belongsToMany(Service::class, 'proof_item_service');
    }

    /** @return BelongsToMany<Market, $this> */
    public function markets(): BelongsToMany
    {
        return $this->belongsToMany(Market::class, 'proof_item_market');
    }

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'type' => ProofType::class,
            'payload' => 'array',
            'is_substantiated' => 'boolean',
        ];
    }
}
