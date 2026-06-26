<?php

namespace App\Build;

use App\Enums\MarketTier;
use App\Enums\ServiceSiloRole;
use App\Enums\SiloType;
use App\Enums\SpokePageType;
use App\Enums\SpokeStatus;
use App\Enums\SpokeTag;
use App\Models\CoverageArea;
use App\Models\Market;
use App\Models\Scopes\SiteScope;
use App\Models\Service;
use App\Models\Silo;
use App\Models\Site;
use App\Models\Spoke;
use App\Onboarding\IntakeCollector;

/**
 * Projects the guided flow's planning structure into the §1 grounding entities the drafter actually
 * reads, on two axes: the SERVICE axis (SiloBlueprint → Spokes → a §4 {@see Silo} per grouping + a §1
 * {@see Service} per service-bearing spoke, attached to its silo) and the GEO axis (Territory →
 * page-selected {@see CoverageArea} → a §1 {@see Market} per town). Without this, a guided-flow site
 * has spokes/towns but no §1 Service/Market — so PageGroundingAssembler resolves nothing and every
 * page reads "Not ready yet". With it, service pages ground to their own service (silo-scoped) and
 * location pages to their own town, and the milestone is walkable on a pure guided site.
 *
 * Idempotent: silos/services/markets are keyed on (site_id, name), so a re-finalize reconciles rather
 * than duplicates. ProofItem/Offer enrichment and Census demographics stay with their own intake
 * ({@see IntakeCollector}); this is the spine that unblocks generation.
 */
class GuidedEntityProjector
{
    /**
     * @return array<string, Silo> the projected silos keyed by silo name (for pinning page silo_id)
     */
    public function project(Site $site): array
    {
        $spokes = Spoke::withoutGlobalScope(SiteScope::class)
            ->where('site_id', $site->id)
            ->get()
            ->reject(fn (Spoke $s) => $s->status === SpokeStatus::Skipped || $s->tag === SpokeTag::Fringe);

        $silos = [];
        foreach ($spokes as $spoke) {
            $name = $this->siloName($spoke);
            $silos[$name] ??= Silo::withoutGlobalScope(SiteScope::class)->firstOrCreate(
                ['site_id' => $site->id, 'name' => $name],
                ['type' => SiloType::ServicePillar],
            );
        }

        foreach ($spokes as $spoke) {
            if (! $this->isService($spoke)) {
                continue;
            }

            $service = Service::withoutGlobalScope(SiteScope::class)->firstOrCreate(
                ['site_id' => $site->id, 'name' => (string) $spoke->name],
                ['silo_role' => $spoke->is_pillar ? ServiceSiloRole::Pillar : ServiceSiloRole::Supporting],
            );

            $silo = $silos[$this->siloName($spoke)] ?? null;
            if ($silo !== null) {
                $service->silos()->syncWithoutDetaching([$silo->id]);
            }
        }

        $this->projectTerritories($site);

        return $silos;
    }

    /**
     * Project the guided Territory layer into §1 Markets: each page-selected {@see CoverageArea}
     * (the towns the client chose for location pages) becomes a §1 Market, so a location page can
     * ground and generate instead of reading "Not ready yet". The geo-axis sibling of the
     * spoke→Service spine. Census demographics/neighborhoods stay with their own intake
     * ({@see IntakeCollector::saveMarkets}); this is the spine that unblocks generation.
     *
     * Idempotent: keyed on (site_id, name) so a re-finalize reconciles rather than duplicates. An
     * owner-added (manual/directed) town is a Priority market; auto county towns are Coverage.
     */
    private function projectTerritories(Site $site): void
    {
        $towns = CoverageArea::withoutGlobalScope(SiteScope::class)
            ->where('site_id', $site->id)
            ->where('page_selected', true)
            ->get();

        foreach ($towns as $town) {
            Market::withoutGlobalScope(SiteScope::class)->firstOrCreate(
                ['site_id' => $site->id, 'name' => (string) $town->name],
                [
                    'region' => $town->state,
                    'geo_id' => $town->geo_id,
                    'lat' => $town->lat,
                    'lng' => $town->lng,
                    'tier' => $town->source === 'manual' ? MarketTier::Priority : MarketTier::Coverage,
                    'is_covered' => true,
                ],
            );
        }
    }

    /**
     * The §1 Market a materialized location page should pin as its subject, resolved from its source
     * CoverageArea (the BuildPage page_key for a location). Keyed identically to
     * {@see projectTerritories()} — (site_id, town name) — so it returns the same Market the
     * projection created. This is what lets grounding foreground a /clifton-nj page on Clifton.
     */
    public function marketForCoverageArea(?string $coverageAreaId, Site $site): ?Market
    {
        if ($coverageAreaId === null) {
            return null;
        }

        $town = CoverageArea::withoutGlobalScope(SiteScope::class)->find($coverageAreaId);
        if ($town === null) {
            return null;
        }

        return Market::withoutGlobalScope(SiteScope::class)
            ->where('site_id', $site->id)
            ->where('name', (string) $town->name)
            ->first();
    }

    /** The §4 Silo a materialized page should pin, resolved from its source spoke (null if none). */
    public function siloForSpoke(?string $spokeId, Site $site): ?Silo
    {
        if ($spokeId === null) {
            return null;
        }

        $spoke = Spoke::withoutGlobalScope(SiteScope::class)->find($spokeId);
        if ($spoke === null) {
            return null;
        }

        return Silo::withoutGlobalScope(SiteScope::class)
            ->where('site_id', $site->id)
            ->where('name', $this->siloName($spoke))
            ->first();
    }

    /**
     * The §1 Service a materialized service page should pin as its subject, resolved from its source
     * spoke (null if the spoke isn't service-bearing or wasn't projected). Keyed identically to
     * {@see project()} — (site_id, exact spoke name) — so it returns the same Service the projection
     * created. This is what lets grounding scope a /toilet-replacement page to the toilet-replacement
     * Service, not every sibling in the silo.
     */
    public function serviceForSpoke(?string $spokeId, Site $site): ?Service
    {
        if ($spokeId === null) {
            return null;
        }

        $spoke = Spoke::withoutGlobalScope(SiteScope::class)->find($spokeId);
        if ($spoke === null || ! $this->isService($spoke)) {
            return null;
        }

        return Service::withoutGlobalScope(SiteScope::class)
            ->where('site_id', $site->id)
            ->where('name', (string) $spoke->name)
            ->first();
    }

    /** A pillar heads its own silo (its name); a child carries the parent silo name in `silo`. */
    private function siloName(Spoke $spoke): string
    {
        $silo = $spoke->silo;

        return $silo !== null && trim($silo) !== '' ? $silo : (string) $spoke->name;
    }

    /** Pillars (the category) and own-page service spokes become §1 Services. */
    private function isService(Spoke $spoke): bool
    {
        return $spoke->is_pillar || $spoke->page_type === SpokePageType::Service;
    }
}
