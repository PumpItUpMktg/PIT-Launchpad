<?php

namespace App\Filament\Pages\Operate;

use App\Models\Site;
use App\Operate\TierProgression;
use App\Operator\ActiveTenant;

/**
 * Operate · Tier progression — the tiered-rollout board. Town pages grouped by MARKET → TIER band → town
 * pills, so the operator sees the roll-out state at a glance: which tiers are Complete / Indexing / Locked,
 * what a locked band is waiting on, and (the leading signal) the inbound-link count per town. A market with
 * a problem (built-but-not-indexed towns) sorts to the top and auto-expands. Read-only over
 * {@see TierProgression}; selection/publish live on the Locations and Location-pages boards.
 *
 * @property-read list<array<string, mixed>> $progression
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
        // The working tenant is the locked ActiveTenant (Portfolio / topbar switcher); no per-page selection.
        $this->siteId = app(ActiveTenant::class)->id();
    }

    /** @return list<array<string, mixed>> */
    public function getProgressionProperty(): array
    {
        $site = is_string($this->siteId) ? Site::query()->find($this->siteId) : null;

        return $site !== null ? app(TierProgression::class)->forSite($site) : [];
    }
}
