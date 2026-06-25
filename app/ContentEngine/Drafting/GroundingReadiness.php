<?php

namespace App\ContentEngine\Drafting;

use App\Enums\PageType;
use App\Models\Content;
use App\Models\Market;
use App\Models\Scopes\SiteScope;
use App\Models\Service;

/**
 * Can this planned page resolve to REAL grounding — i.e. would {@see PageGroundingAssembler} feed the
 * drafter actual intake entities, not an empty prompt? It mirrors the assembler's resolution so the
 * surfaces can gate generation honestly: a page generates for real only when grounded; everything
 * else shows "grounding pending" rather than faking an empty draft (DraftGuard would reject it anyway).
 *
 * - **Service-family pages** (service / pillar / cluster) need ≥1 resolvable §1 Service — silo-scoped
 *   when the page pins a `silo_id`, else the assembler's site-wide fallback.
 * - **Location pages** need ≥1 §1 Market for the site.
 * - **Home / hub / utility** have no entity-grounding path yet → always pending.
 *
 * NOTE (the spoke→Service gap): the guided flow materializes pages without a `silo_id` and does not
 * yet create §1 Service/Market entities (those come from §7a intake), so a pure guided-flow site reads
 * as "grounding pending" until that intake→§1 wiring lands — which is the honest state, not a bug here.
 */
class GroundingReadiness
{
    /** @var array<string, bool> site_id => has any §1 Service (per-request memo) */
    private array $serviceSites = [];

    /** @var array<string, bool> site_id => has any §1 Market (per-request memo) */
    private array $marketSites = [];

    public function ready(Content $page): bool
    {
        return match ($page->page_type) {
            PageType::Service, PageType::Pillar, PageType::Cluster => $this->hasServices($page),
            PageType::Location => $this->hasMarkets($page),
            default => false, // home / hub / utility — no entity grounding wired yet
        };
    }

    private function hasServices(Content $page): bool
    {
        $siteId = (string) $page->site_id;

        // A silo-scoped match is the real, page-specific grounding; the site-wide fallback mirrors
        // the assembler so a page is still "grounded" when no silo is pinned but services exist.
        if ($page->silo_id !== null
            && Service::withoutGlobalScope(SiteScope::class)
                ->where('site_id', $siteId)
                ->whereHas('silos', fn ($q) => $q->withoutGlobalScope(SiteScope::class)->whereKey($page->silo_id))
                ->exists()
        ) {
            return true;
        }

        return $this->serviceSites[$siteId] ??= Service::withoutGlobalScope(SiteScope::class)
            ->where('site_id', $siteId)->exists();
    }

    private function hasMarkets(Content $page): bool
    {
        $siteId = (string) $page->site_id;

        return $this->marketSites[$siteId] ??= Market::withoutGlobalScope(SiteScope::class)
            ->where('site_id', $siteId)->exists();
    }
}
