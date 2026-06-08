<?php

namespace App\KeywordGenerator\Beatability;

use App\Enums\BeatabilityLane;
use App\Enums\SiteAuthorityTier;
use App\Models\PositionSnapshot;
use App\Models\Scopes\SiteScope;
use App\Models\Site;

/**
 * Coarse, self-calibrating site-authority tier. Inferred from organic
 * position-tracking history (median rank); defaults to "new" with no data.
 */
class SiteAuthority
{
    public function tierFor(Site $site): SiteAuthorityTier
    {
        $ranks = PositionSnapshot::withoutGlobalScope(SiteScope::class)
            ->where('site_id', $site->id)
            ->where('lane', BeatabilityLane::Organic->value)
            ->whereNotNull('rank')
            ->pluck('rank')
            ->map(fn ($r) => (int) $r)
            ->sort()
            ->values();

        if ($ranks->isEmpty()) {
            return SiteAuthorityTier::New;
        }

        $median = $ranks[intdiv($ranks->count(), 2)];

        return match (true) {
            $median <= 10 => SiteAuthorityTier::Established,
            $median <= 30 => SiteAuthorityTier::Developing,
            default => SiteAuthorityTier::New,
        };
    }
}
