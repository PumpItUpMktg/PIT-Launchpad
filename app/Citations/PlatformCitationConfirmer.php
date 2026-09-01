<?php

namespace App\Citations;

use App\Enums\CitationPresence;
use App\Enums\CitationSource;
use App\Models\CitationStatus;
use App\Models\Directory;
use App\Models\Location;
use App\Models\Scopes\SiteScope;
use Illuminate\Support\Carbon;

/**
 * Confirms the "requires client action" directories that are owned through a platform integration rather than
 * the organic scan (§ Citations) — today just Google Business Profile. A GBP-backed location (it carries a
 * `place_id` / `gbp_url` the platform imported) IS listed on Google by definition, and its canonical NAP was
 * derived from that GBP, so the listing is present-and-correct by construction. The organic scan can't see the
 * map listing as a result domain, so without this GBP always reads "missing" for a location that plainly has one.
 *
 * Runs after the scan + reconcile so it wins over the reconciler's "unfound → gap". Apple Maps / Bing / Facebook
 * are also platform-confirmed directories, but their integrations aren't wired yet, so they stay for manual review.
 */
final class PlatformCitationConfirmer
{
    private const GBP_DOMAIN = 'google.com';

    /** @return int number of platform listings confirmed */
    public function confirm(Location $location): int
    {
        if (blank($location->place_id) && blank($location->gbp_url)) {
            return 0; // not GBP-backed — nothing the platform can vouch for
        }

        $gbp = Directory::query()->where('is_active', true)->where('domain', self::GBP_DOMAIN)->first();
        if ($gbp === null) {
            return 0; // catalog not seeded / GBP not in it
        }

        $now = Carbon::now();
        CitationStatus::query()->withoutGlobalScope(SiteScope::class)->updateOrCreate(
            ['location_id' => $location->id, 'directory_id' => $gbp->id],
            [
                'site_id' => $location->site_id,
                'presence' => CitationPresence::PresentMatch,
                'needs_review' => false,
                'found_url' => $location->gbp_url,
                'attributed_location_id' => $location->id,
                'attribution_confidence' => 100,
                'source' => CitationSource::Platform,
                'last_scanned_at' => $now,
            ],
        );

        CitationStatus::query()->withoutGlobalScope(SiteScope::class)
            ->where('location_id', $location->id)->where('directory_id', $gbp->id)
            ->whereNull('first_seen_at')
            ->update(['first_seen_at' => $now]);

        return 1;
    }
}
