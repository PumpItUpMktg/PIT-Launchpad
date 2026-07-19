<?php

namespace App\Models;

use App\Enums\SiteStatus;
use App\Models\Scopes\VisibleSiteScope;
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
 * @property string|null $header_tone_override operator override for the header bar: 'light' | 'dark' | null (auto from logo)
 * @property bool $offers_emergency
 * @property string|null $phone corporate/main business phone (intake) — the site-wide number
 * @property string|null $emergency_phone corporate after-hours line (intake)
 * @property string|null $corporate_street corporate/site-wide address (intake), distinct from any Location's NAP
 * @property string|null $corporate_city
 * @property string|null $corporate_state
 * @property string|null $corporate_postal_code
 * @property StyleVariation|null $style_variation
 * @property bool $use_logo_colors
 * @property string|null $license_number trust fact (gathering relay) — manual or interview-seeded
 * @property bool|null $insured trust fact — null = unknown, distinct from "no"
 * @property int|null $years_in_business trust fact
 * @property string|null $warranty_program trust fact
 * @property string|null $guarantees trust fact
 */
class Site extends Model
{
    /** @use HasFactory<SiteFactory> */
    use HasFactory, HasUlids;

    protected $guarded = [];

    protected static function booted(): void
    {
        // Gating layer 1: every Site query is limited to the actor's permitted set (no-op for admins /
        // no-membership operators / console). Portfolio, the switcher list, and tenant resolution all
        // flow through Site::query(), so a non-permitted site is invisible everywhere at once.
        static::addGlobalScope(new VisibleSiteScope);
    }

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

    /**
     * The corporate / site-wide address as one display line — "10 Main St, Springfield, NJ 07081" —
     * assembled from the structured intake fields (street + city + state + postal). This is the
     * site-wide NAP address for the header/footer chrome, distinct from any physical Location's own
     * address. Null when no corporate address was captured (readers fall back to the primary location).
     */
    public function corporateAddressLine(): ?string
    {
        $street = trim((string) $this->corporate_street);
        $regionZip = trim(trim((string) $this->corporate_state).' '.trim((string) $this->corporate_postal_code));
        $cityRegion = implode(', ', array_filter([
            trim((string) $this->corporate_city),
            $regionZip,
        ], fn (string $p): bool => $p !== ''));

        $line = implode(', ', array_filter([$street, $cityRegion], fn (string $p): bool => $p !== ''));

        return $line !== '' ? $line : null;
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
            'insured' => 'boolean',
            'years_in_business' => 'integer',
        ];
    }
}
