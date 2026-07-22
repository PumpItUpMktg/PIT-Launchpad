<?php

namespace App\Models;

use App\Enums\GeoApplicability;
use App\Enums\ServiceSiloRole;
use App\Models\Concerns\BelongsToSite;
use Database\Factories\ServiceFactory;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
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
 * @property ServiceSiloRole $silo_role pillar (core) vs supporting — drives silo + nav ranking
 * @property string|null $structure_home_cluster_id demand-derived home cluster (keyword-first)
 * @property bool $structure_home_flagged mapped to nearest cluster with no true match — needs review
 * @property bool $force_page owner guarantee: always build a page for this service (wins over demand)
 * @property string|null $forced_silo the topic (silo name) a force_page service's page lives under
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

    /**
     * The demand-derived structure home — the keyword cluster (≈ silo) this service maps onto, assigned
     * by service→cluster matching at derivation (keyword-first). Null before derivation runs.
     *
     * @return BelongsTo<KeywordCluster, $this>
     */
    public function structureHome(): BelongsTo
    {
        return $this->belongsTo(KeywordCluster::class, 'structure_home_cluster_id');
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
            'structure_home_flagged' => 'boolean',
            'force_page' => 'boolean',
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
