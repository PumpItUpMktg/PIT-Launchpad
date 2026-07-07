<?php

namespace App\Models;

use App\Enums\SiteStatus;
use App\Styling\StyleVariation;
use Database\Factories\SiteFactory;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

/**
 * @property SiteStatus $status
 * @property int|null $budget_ceiling
 * @property int|null $silo_own_page_bar
 * @property array<string, int>|null $coverage_thresholds
 * @property string|null $domain_url
 * @property string $brand_name
 * @property bool $offers_emergency
 * @property StyleVariation|null $style_variation
 * @property bool $use_logo_colors
 */
class Site extends Model
{
    /** @use HasFactory<SiteFactory> */
    use HasFactory, HasUlids;

    protected $guarded = [];

    /** @return BelongsTo<Account, $this> */
    public function account(): BelongsTo
    {
        return $this->belongsTo(Account::class);
    }

    /** @return HasOne<SiteBranding, $this> */
    public function branding(): HasOne
    {
        return $this->hasOne(SiteBranding::class);
    }

    /** @return HasOne<ConversionConfig, $this> */
    public function conversionConfig(): HasOne
    {
        return $this->hasOne(ConversionConfig::class);
    }

    /** @return HasOne<SetupState, $this> */
    public function setupState(): HasOne
    {
        return $this->hasOne(SetupState::class);
    }

    /** @return HasMany<Membership, $this> */
    public function memberships(): HasMany
    {
        return $this->hasMany(Membership::class);
    }

    /** @return HasMany<Location, $this> */
    public function locations(): HasMany
    {
        return $this->hasMany(Location::class);
    }

    /** @return HasMany<Silo, $this> */
    public function silos(): HasMany
    {
        return $this->hasMany(Silo::class);
    }

    /** @return HasMany<Service, $this> */
    public function services(): HasMany
    {
        return $this->hasMany(Service::class);
    }

    /** @return HasMany<Market, $this> */
    public function markets(): HasMany
    {
        return $this->hasMany(Market::class);
    }

    /** @return HasMany<VoiceProfile, $this> */
    public function voiceProfiles(): HasMany
    {
        return $this->hasMany(VoiceProfile::class);
    }

    /** @return HasMany<ProofItem, $this> */
    public function proofItems(): HasMany
    {
        return $this->hasMany(ProofItem::class);
    }

    /** @return HasMany<Competitor, $this> */
    public function competitors(): HasMany
    {
        return $this->hasMany(Competitor::class);
    }

    /** @return HasMany<Offer, $this> */
    public function offers(): HasMany
    {
        return $this->hasMany(Offer::class);
    }

    /** @return HasMany<Goal, $this> */
    public function goals(): HasMany
    {
        return $this->hasMany(Goal::class);
    }

    /** @return HasMany<Keyword, $this> */
    public function keywords(): HasMany
    {
        return $this->hasMany(Keyword::class);
    }

    /** @return HasMany<Content, $this> */
    public function contents(): HasMany
    {
        return $this->hasMany(Content::class);
    }

    /** @return HasMany<Source, $this> */
    public function sources(): HasMany
    {
        return $this->hasMany(Source::class);
    }

    /** @return HasMany<WireframeKit, $this> */
    public function wireframeKits(): HasMany
    {
        return $this->hasMany(WireframeKit::class);
    }

    /** @return HasMany<MediaAsset, $this> */
    public function mediaAssets(): HasMany
    {
        return $this->hasMany(MediaAsset::class);
    }

    /** @return HasMany<RenderJob, $this> */
    public function renderJobs(): HasMany
    {
        return $this->hasMany(RenderJob::class);
    }

    /** @return HasMany<SourceDocument, $this> */
    public function sourceDocuments(): HasMany
    {
        return $this->hasMany(SourceDocument::class);
    }

    /** @return HasMany<Redirect, $this> */
    public function redirects(): HasMany
    {
        return $this->hasMany(Redirect::class);
    }

    /** @return HasMany<Connection, $this> */
    public function connections(): HasMany
    {
        return $this->hasMany(Connection::class);
    }

    public function isLive(): bool
    {
        return $this->status === SiteStatus::Live;
    }

    /**
     * The tenant's 4-tier coverage thresholds, merged over the platform defaults — drives
     * the size_tier grouping of covered towns. Per-site overrides live in the
     * `coverage_thresholds` JSON column; any missing key falls back to config.
     *
     * @return array{major: int, large: int, medium: int}
     */
    public function coverageThresholds(): array
    {
        $defaults = (array) config('launchpad.locations.size_tiers', []);
        $override = is_array($this->coverage_thresholds) ? $this->coverage_thresholds : [];

        return [
            'major' => (int) ($override['major'] ?? $defaults['major'] ?? 50000),
            'large' => (int) ($override['large'] ?? $defaults['large'] ?? 30000),
            'medium' => (int) ($override['medium'] ?? $defaults['medium'] ?? 15000),
        ];
    }

    /**
     * The silo own-page bar — the volume floor at/above which a core spoke pre-checks for its
     * own page in the prune (below it folds into the pillar). Per-site override over the single
     * platform knob (config launchpad.silo_volume.fold_threshold). Same value the volume
     * re-ground uses for its granularity recommendation — no parallel knob.
     */
    public function ownPageBar(): int
    {
        return (int) ($this->silo_own_page_bar ?? config('launchpad.silo_volume.fold_threshold', 100));
    }

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'slug_conventions' => 'array',
            'status' => SiteStatus::class,
            'offers_emergency' => 'boolean',
            'style_variation' => StyleVariation::class,
            'use_logo_colors' => 'boolean',
            'budget_ceiling' => 'integer',
            'silo_own_page_bar' => 'integer',
            'coverage_thresholds' => 'array',
        ];
    }
}
