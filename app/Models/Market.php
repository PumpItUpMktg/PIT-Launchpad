<?php

namespace App\Models;

use App\Enums\MarketTier;
use App\Models\Concerns\BelongsToSite;
use Database\Factories\MarketFactory;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;

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
        ];
    }
}
