<?php

namespace App\KeywordGenerator\Derive;

use App\Enums\SpokeGranularity;
use App\Enums\SpokeStatus;
use App\Enums\SpokeTag;
use App\Models\Scopes\SiteScope;
use App\Models\Service;
use App\Models\SiloBlueprint;
use App\Models\Site;
use App\Models\Spoke;

/**
 * The coverage guarantee: the demand-first structure builds pages from search volume, so a stated
 * service the owner really performs but with thin demand (mold testing, radon, water-damage cleanup)
 * can earn no page. When the owner marks such a service `force_page` and pins it to a topic
 * (`forced_silo`), THIS reconciler makes sure an own-page spoke exists for it — and it runs on every
 * (re)build, so the page survives a full rebuild-from-scratch instead of silently dropping.
 *
 * Idempotent: it never duplicates a spoke that already covers the service, in any topic.
 */
final class ServicePageGuarantee
{
    /** @return int the number of pages materialized to honor the guarantee */
    public function ensure(Site $site): int
    {
        $blueprint = SiloBlueprint::withoutGlobalScope(SiteScope::class)->where('site_id', $site->id)->first();
        if ($blueprint === null) {
            return 0;
        }

        $services = Service::withoutGlobalScope(SiteScope::class)
            ->where('site_id', $site->id)
            ->where('force_page', true)
            ->get();
        if ($services->isEmpty()) {
            return 0;
        }

        // The spoke names already in the tree (any topic), as a lookup set — so a service the derivation
        // already gave a page is never doubled.
        $seen = [];
        foreach (Spoke::withoutGlobalScope(SiteScope::class)->where('site_id', $site->id)->pluck('name') as $existingName) {
            $seen[$this->key((string) $existingName)] = true;
        }

        $built = 0;
        foreach ($services as $service) {
            $name = trim((string) $service->name);
            $silo = trim((string) $service->forced_silo);
            if ($name === '' || $silo === '' || isset($seen[$this->key($name)])) {
                continue; // already covered, or not pinned to a topic yet
            }

            Spoke::create([
                'silo_blueprint_id' => $blueprint->id,
                'site_id' => $site->id,
                'name' => $name,
                'silo' => $silo,
                'is_pillar' => false,
                'status' => SpokeStatus::Offered,
                'granularity' => SpokeGranularity::OwnPage,
                'tag' => SpokeTag::Core,
                'volume' => null,
            ]);
            $seen[$this->key($name)] = true;
            $built++;
        }

        return $built;
    }

    private function key(string $name): string
    {
        return mb_strtolower(trim($name));
    }
}
