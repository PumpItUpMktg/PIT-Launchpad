<?php

namespace App\Models;

use App\Enums\GeoApplicability;
use App\Enums\ServicePageTreatment;
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
 * @property string|null $parent_service_id the parent service this is grouped under (null = top-level; deferred-FK, self-ref)
 * @property ServicePageTreatment $page_treatment child treatment: its own page vs a section on the parent (default section)
 * @property int|null $group_order manual order within a group / among top-level services
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

    /**
     * The parent service this is grouped under (null = top-level). Deferred-FK / self-ref: resolved at
     * the model level, not a DB constraint (§1 convention).
     *
     * @return BelongsTo<Service, $this>
     */
    public function parentService(): BelongsTo
    {
        return $this->belongsTo(self::class, 'parent_service_id');
    }

    /**
     * The sub-services grouped under this one, ordered by the manual group order then name.
     *
     * @return HasMany<Service, $this>
     */
    public function childServices(): HasMany
    {
        return $this->hasMany(self::class, 'parent_service_id')->orderBy('group_order')->orderBy('name');
    }

    /**
     * Whether this service renders as a HUB (category) page rather than a standalone service page — the
     * derived structural rule that replaces AI guessing: a service is a hub IFF it has ≥1 child treated
     * as its own PAGE. A service with no children, or only SECTION children, is a single service page
     * (its sections fold in) — so a service can never become a spoke-less "thin hub". Never stored; this
     * is the authority the structure writer and the build key on.
     */
    public function isHub(): bool
    {
        return $this->childServices()
            ->where('page_treatment', ServicePageTreatment::Page->value)
            ->exists();
    }

    /**
     * Whether this service may take sub-services — enforcing the 2-level cap: a service that is itself a
     * child (has a parent) can never be a parent. Guards the UI ("add sub-service") and the writer.
     */
    public function canHaveChildren(): bool
    {
        return $this->parent_service_id === null;
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
            'page_treatment' => ServicePageTreatment::class,
            'group_order' => 'integer',
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

    /**
     * A "thin" service has no page-body enrichment: none of the four record fields that drive a spoke
     * page's mid-page sections — symptoms (Signs you need this), scope_items (What's included),
     * process_steps (What to expect), cost_factors (What it costs). A spoke page for a thin service
     * renders only its hero + intro + FAQ, so those sections omit and the page reads sparse. The single
     * source of truth for the "needs enrichment" flag (pages board + review queue) and the readiness chip.
     */
    public function isThin(): bool
    {
        foreach (['symptoms', 'scope_items', 'process_steps', 'cost_factors'] as $field) {
            if (is_array($this->{$field}) && $this->{$field} !== []) {
                return false;
            }
        }

        return true;
    }
}
