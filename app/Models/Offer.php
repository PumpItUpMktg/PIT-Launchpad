<?php

namespace App\Models;

use App\Models\Concerns\BelongsToSite;
use Database\Factories\OfferFactory;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;

class Offer extends Model
{
    /** @use HasFactory<OfferFactory> */
    use BelongsToSite, HasFactory, HasUlids;

    protected $guarded = [];

    /** @return BelongsToMany<Service, $this> */
    public function services(): BelongsToMany
    {
        return $this->belongsToMany(Service::class, 'offer_service');
    }

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'active_window' => 'array',
        ];
    }
}
