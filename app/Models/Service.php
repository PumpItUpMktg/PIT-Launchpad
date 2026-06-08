<?php

namespace App\Models;

use App\Enums\GeoApplicability;
use App\Enums\ServiceSiloRole;
use App\Models\Concerns\BelongsToSite;
use Database\Factories\ServiceFactory;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Service extends Model
{
    /** @use HasFactory<ServiceFactory> */
    use BelongsToSite, HasFactory, HasUlids;

    protected $guarded = [];

    /** @return HasMany<ServiceProblem, $this> */
    public function problems(): HasMany
    {
        return $this->hasMany(ServiceProblem::class);
    }

    /** @return BelongsToMany<Silo, $this> */
    public function silos(): BelongsToMany
    {
        return $this->belongsToMany(Silo::class, 'silo_service');
    }

    /** @return BelongsToMany<Market, $this> */
    public function markets(): BelongsToMany
    {
        return $this->belongsToMany(Market::class, 'market_service');
    }

    /** @return BelongsToMany<ProofItem, $this> */
    public function proofItems(): BelongsToMany
    {
        return $this->belongsToMany(ProofItem::class, 'proof_item_service');
    }

    /** @return BelongsToMany<Offer, $this> */
    public function offers(): BelongsToMany
    {
        return $this->belongsToMany(Offer::class, 'offer_service');
    }

    /** @return BelongsToMany<MediaAsset, $this> */
    public function mediaAssets(): BelongsToMany
    {
        return $this->belongsToMany(MediaAsset::class, 'media_asset_service');
    }

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'silo_role' => ServiceSiloRole::class,
            'geo_applicability' => GeoApplicability::class,
            'peak_months' => 'array',
            'is_most_profitable' => 'boolean',
            'is_growth_priority' => 'boolean',
        ];
    }
}
