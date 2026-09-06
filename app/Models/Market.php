<?php

namespace App\Models;

use App\Enums\MarketTier;
use App\Models\Concerns\BelongsToSite;
use App\Support\GeoBounds;
use Carbon\CarbonInterface;
use Database\Factories\MarketFactory;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;

/**
 * @property MarketTier $tier
 * @property array<string, mixed>|null $demographics Census ACS payload (keyed, e.g. `population`)
 * @property list<string>|null $neighborhoods
 * @property bool $on_hold advisory hold (no publish effect) — set/released by an operator
 * @property CarbonInterface|null $release_at the target release date for a held market
 */
class Market extends Model
{
    /** @use HasFactory<MarketFactory> */
    use BelongsToSite, HasFactory, HasUlids;

    protected $guarded = [];

    /** @return BelongsToMany<Service, $this> */
    public function services(): BelongsToMany
    {
        return $this->belongsToMany(Service::class, 'market_service');
    }

    /** @return BelongsToMany<ProofItem, $this> */
    public function proofItems(): BelongsToMany
    {
        return $this->belongsToMany(ProofItem::class, 'proof_item_market');
    }

    /** @return BelongsToMany<MediaAsset, $this> */
    public function mediaAssets(): BelongsToMany
    {
        return $this->belongsToMany(MediaAsset::class, 'media_asset_market');
    }

    /**
     * A held market whose target release date has passed — the operator meant to release it and hasn't
     * (release is manual). This is what the lobby's Tier-2 "held market past its release date" badge flags.
     */
    public function releaseOverdue(): bool
    {
        return $this->on_hold && $this->release_at !== null && $this->release_at->isPast();
    }

    /**
     * Whether this market carries a coordinate that plausibly falls in the US service area — the
     * precondition for centring a local-pack grid on it. Null or out-of-area geo (e.g. a South-Pacific
     * geocode error) is NOT valid: it would query open ocean. The geo report keys off this.
     */
    public function hasValidGeo(): bool
    {
        return GeoBounds::isWithinServiceArea(
            $this->lat !== null ? (float) $this->lat : null,
            $this->lng !== null ? (float) $this->lng : null,
        );
    }

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'tier' => MarketTier::class,
            'lat' => 'decimal:7',
            'lng' => 'decimal:7',
            'demographics' => 'array',
            'neighborhoods' => 'array',
            'is_covered' => 'boolean',
            'on_hold' => 'boolean',
            'release_at' => 'datetime',
        ];
    }
}
