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

/**
 * @property string|null $short_description one-liner for hub cards
 * @property list<string>|null $symptoms "signs you need this" bullets (spoke-page hook)
 * @property list<string>|null $scope_items what's included (checked list)
 * @property list<string>|null $process_steps ordered steps; tenant default process when empty
 * @property list<string>|null $cost_factors what drives the price
 * @property array{low?: numeric, high?: numeric, unit?: string}|null $price_range optional honest range; absent ⇒ factors-only cost section
 * @property array{enabled?: bool, title?: string, option_a?: array{name?: string, points?: list<string>}, option_b?: array{name?: string, points?: list<string>}, verdict?: string}|null $comparison owner-triggered per spoke, off by default
 * @property bool $warranty_applicable pulls the warranty trust copy onto the page when true
 */
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
            'symptoms' => 'array',
            'scope_items' => 'array',
            'process_steps' => 'array',
            'cost_factors' => 'array',
            'price_range' => 'array',
            'comparison' => 'array',
            'warranty_applicable' => 'boolean',
        ];
    }
}
