<?php

namespace App\TownPages;

use App\Enums\ServiceSiloRole;
use App\Models\Content;
use App\Models\Keyword;
use App\Models\Scopes\SiteScope;
use App\Models\Service;
use App\Models\Silo;
use App\Models\Site;
use InvalidArgumentException;

/**
 * The town-page PRIORITY keywords (§ Town Pages): the few tracked keywords — at most
 * {@see max()} per site — the operator wants pushed up on the "{keyword} {Town}" searches. Each one earns
 * its own drafted H2 section and two FAQ items on the town pages that have the demand for it; everything
 * else on a town page stays as it is, so the page never becomes a service list again.
 *
 * Which towns carry how many is a population TIER, not a rotation: a town at or above the full threshold
 * carries every priority keyword, one above the partial threshold carries the first (rank 1) only, and a
 * hamlet below that carries none — the same three keywords everywhere they are searched, never a different
 * three per neighbour (the search is "{keyword} {Town}", and only the page whose title names that town can
 * answer it).
 */
final class PriorityKeywords
{
    public const TIER_FULL = 'full';

    public const TIER_PARTIAL = 'partial';

    public const TIER_NONE = 'none';

    /** The priority keywords of a site, rank order. @return list<Keyword> */
    public function for(Site $site): array
    {
        return Keyword::withoutGlobalScope(SiteScope::class)
            ->where('site_id', $site->id)
            ->whereNotNull('town_priority_rank')
            ->orderBy('town_priority_rank')
            ->orderBy('query')
            ->get()
            ->all();
    }

    /** The priority keywords a town of this population carries. @return list<Keyword> */
    public function forPopulation(Site $site, int $population): array
    {
        $tier = self::tier($population);
        if ($tier === self::TIER_NONE) {
            return [];
        }
        $keywords = $this->for($site);

        return $tier === self::TIER_FULL ? $keywords : array_slice($keywords, 0, 1);
    }

    /** The population tier: full / partial / none. */
    public static function tier(int $population): string
    {
        if ($population >= self::fullPopulation()) {
            return self::TIER_FULL;
        }
        if ($population >= self::partialPopulation()) {
            return self::TIER_PARTIAL;
        }

        return self::TIER_NONE;
    }

    /**
     * Make a keyword a priority (next free rank) or take it off the list (the ranks behind it close up).
     *
     * @throws InvalidArgumentException when the list is full
     */
    public function set(Site $site, Keyword $keyword, bool $priority): Keyword
    {
        if ($keyword->site_id !== $site->id) {
            throw new InvalidArgumentException('That keyword belongs to another site.');
        }
        if ($priority === ($keyword->town_priority_rank !== null)) {
            return $keyword;
        }
        if ($priority) {
            $current = $this->for($site);
            if (count($current) >= self::max()) {
                throw new InvalidArgumentException(sprintf('Up to %d priority keywords — take one off the list first.', self::max()));
            }
            $keyword->forceFill(['town_priority_rank' => count($current) + 1])->save();

            return $keyword->refresh();
        }

        $keyword->forceFill(['town_priority_rank' => null])->save();
        $rank = 1;
        foreach ($this->for($site) as $other) {
            if ((int) $other->town_priority_rank !== $rank) {
                $other->forceFill(['town_priority_rank' => $rank])->save();
            }
            $rank++;
        }

        return $keyword->refresh();
    }

    /**
     * The service a priority keyword is about — the section links to that service's page and replaces its
     * generic card. Resolved from the keyword's own target page (a service page's primary service), else its
     * silo's pillar service, else the silo's first service; null when nothing ties it to the catalog.
     */
    public function serviceFor(Keyword $keyword): ?Service
    {
        if ($keyword->target_content_id !== null) {
            $target = Content::withoutGlobalScope(SiteScope::class)->find($keyword->target_content_id);
            if ($target?->primary_service_id !== null) {
                $service = Service::withoutGlobalScope(SiteScope::class)->find($target->primary_service_id);
                if ($service !== null) {
                    return $service;
                }
            }
        }
        if ($keyword->silo_id === null) {
            return null;
        }
        $silo = Silo::withoutGlobalScope(SiteScope::class)->find($keyword->silo_id);
        if ($silo === null) {
            return null;
        }
        $services = $silo->services()->withoutGlobalScope(SiteScope::class)->orderBy('created_at')->get();
        foreach ($services as $service) {
            if ($service->silo_role === ServiceSiloRole::Pillar) {
                return $service;
            }
        }

        return $services->first();
    }

    public static function max(): int
    {
        return max(1, (int) config('launchpad.town_pages.priority.max_keywords', 3));
    }

    public static function fullPopulation(): int
    {
        return (int) config('launchpad.town_pages.priority.full_population', 10000);
    }

    public static function partialPopulation(): int
    {
        return (int) config('launchpad.town_pages.priority.partial_population', 3000);
    }
}
