<?php

namespace App\Filament\Pages\Operate;

use App\Models\Site;
use App\Operate\TierProgression;

/**
 * Operate · Tier progression — the tiered-rollout board. Town pages grouped by MARKET → TIER band → town
 * pills, so the operator sees the roll-out state at a glance: which tiers are Complete / Indexing / Locked,
 * what a locked band is waiting on, and (the leading signal) the inbound-link count per town. A market with
 * a problem (built-but-not-indexed towns) sorts to the top and auto-expands. Read-only over
 * {@see TierProgression}; selection/publish live on the Locations and Location-pages boards.
 *
 * @property-read list<array<string, mixed>> $progression
 * @property-read array<string, string> $siteOptions
 */
class OperateTierProgression extends OperatePage
{
    protected static ?string $slug = 'operate/tier-progression';

    protected static ?string $navigationLabel = 'Tier progression';

    protected static ?int $navigationSort = 8;

    protected string $view = 'filament.operate.tier-progression';

    public ?string $siteId = null;

    public function mount(): void
    {
        $requested = request()->query('site');
        $candidate = is_string($requested) ? $requested : session('guided_site_id');

        $site = is_string($candidate) ? Site::query()->find($candidate) : null;
        $site ??= Site::query()->orderBy('brand_name')->first();

        if ($site !== null) {
            session(['guided_site_id' => $site->id]);
            $this->siteId = $site->id;
        }
    }

    public function updatedSiteId(?string $value): void
    {
        if (is_string($value) && $value !== '') {
            session(['guided_site_id' => $value]);
        }
    }

    /** @return list<array<string, mixed>> */
    public function getProgressionProperty(): array
    {
        $site = is_string($this->siteId) ? Site::query()->find($this->siteId) : null;

        return $site !== null ? app(TierProgression::class)->forSite($site) : [];
    }

    /** @return array<string, string> */
    public function getSiteOptionsProperty(): array
    {
        return Site::query()->orderBy('brand_name')->pluck('brand_name', 'id')->all();
    }
}
