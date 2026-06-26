<?php

namespace App\Build;

use App\Enums\ServiceSiloRole;
use App\Enums\SiloType;
use App\Enums\SpokePageType;
use App\Enums\SpokeStatus;
use App\Enums\SpokeTag;
use App\Models\Scopes\SiteScope;
use App\Models\Service;
use App\Models\Silo;
use App\Models\Site;
use App\Models\Spoke;

/**
 * Projects the guided flow's planning structure (SiloBlueprint → Spokes) into the §1 grounding
 * entities the drafter actually reads: a §4 {@see Silo} per silo grouping and a §1 {@see Service}
 * per service-bearing spoke, the service attached to its silo. Without this, a guided-flow site has
 * spokes but no §1 Service/Market — so PageGroundingAssembler resolves nothing and every page reads
 * "grounding pending". With it, service pages ground to their own service (silo-scoped) and the
 * milestone is walkable on a pure guided site.
 *
 * Idempotent: silos/services are keyed on (site_id, name), so a re-finalize reconciles rather than
 * duplicates. ProofItem/Offer/Market enrichment stay with their own intake; this is the spoke→Service
 * spine that unblocks generation.
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

        return $silos;
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
