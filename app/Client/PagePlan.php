<?php

namespace App\Client;

use App\Enums\SpokeGranularity;
use App\Enums\SpokeStatus;
use App\Enums\SpokeTag;
use App\Models\Scopes\SiteScope;
use App\Models\SiloBlueprint;
use App\Models\Site;
use App\Models\Spoke;
use Illuminate\Support\Collection;

/**
 * The §7c client-facing view of the arranged page plan: the page inventory the engine
 * produced for a site, grouped into the silos (topic areas) the client recognises, each
 * page carrying the related topics it also covers (folded sections) and the **lead-upside**
 * — the monthly search volume we're targeting. Operator mechanics (cosine scores, tags,
 * fold targets, flags) are deliberately hidden; this is the value story, read-only.
 *
 * Honest framing (the §7c hard constraint): volume is *search demand we target*, never a
 * promised lead count — the surface never claims caused/attributed leads.
 */
class PagePlan
{
    /**
     * @return array{
     *     silos: list<array{name: string, pages: list<array{name: string, keyword: string, volume: int, kind: string, sections: list<array{name: string, volume: int}>}>, page_count: int, section_count: int, volume: int}>,
     *     totals: array{silos: int, pages: int, sections: int, volume: int}
     * }
     */
    public function for(Site $site): array
    {
        $blueprint = SiloBlueprint::withoutGlobalScope(SiteScope::class)
            ->where('site_id', $site->id)->latest('created_at')->first();

        if ($blueprint === null) {
            return $this->empty();
        }

        /** @var Collection<int, Spoke> $spokes */
        $spokes = Spoke::withoutGlobalScope(SiteScope::class)
            ->where('silo_blueprint_id', $blueprint->id)->get();

        // The plan = everything that will be built or covered: drop the dropped (skipped) and
        // the out-of-lane handoffs (fringe). Candidates and confirmed routings both show.
        $visible = $spokes->reject(fn (Spoke $s) => $s->status === SpokeStatus::Skipped || $s->tag === SpokeTag::Fringe);

        $pillarsBySilo = $visible->filter(fn (Spoke $s) => $s->is_pillar)->keyBy(fn (Spoke $s) => (string) $s->silo);
        $pillarsById = $visible->filter(fn (Spoke $s) => $s->is_pillar)->keyBy('id');

        // One group per top-level silo (a pillar that isn't a demoted sub-hub).
        $groups = [];
        foreach ($visible as $s) {
            if ($s->is_pillar && ! $s->isSubHub()) {
                $groups[(string) $s->silo] = ['name' => (string) $s->silo, 'pages' => [], 'volume' => 0];
            }
        }

        // Place every page (pillar, sub-hub, own-page core) into its top-level silo group.
        $groupOf = []; // page spoke id => top-level silo name
        foreach ($visible as $s) {
            if (! $this->isPage($s)) {
                continue;
            }
            $root = $this->rootSilo($s, $pillarsBySilo, $pillarsById);
            if (! isset($groups[$root])) {
                $groups[$root] = ['name' => $root, 'pages' => [], 'volume' => 0];
            }
            $groups[$root]['pages'][$s->id] = [
                'id' => $s->id,
                'name' => $s->name,
                'keyword' => $this->keyword($s),
                'volume' => $this->volume($s),
                'kind' => $s->is_pillar ? 'hub' : 'page',
                'sort' => $s->is_pillar ? 0 : 1,
                'sections' => [],
            ];
            $groupOf[$s->id] = $root;
        }

        // Folded spokes ride along as the sections of their home page (or the silo pillar).
        foreach ($visible as $s) {
            if ($s->is_pillar || $s->granularity !== SpokeGranularity::Folded) {
                continue;
            }
            $section = ['name' => $s->name, 'volume' => $this->volume($s)];
            $homeId = $s->fold_into_id;
            if ($homeId !== null && isset($groupOf[$homeId])) {
                $groups[$groupOf[$homeId]]['pages'][$homeId]['sections'][] = $section;

                continue;
            }
            // No resolvable home page → attach to its top-level silo's pillar page.
            $root = $this->rootSilo($s, $pillarsBySilo, $pillarsById);
            $pillar = $pillarsBySilo->get($root);
            if ($pillar !== null && isset($groups[$root]['pages'][$pillar->id])) {
                $groups[$root]['pages'][$pillar->id]['sections'][] = $section;
            }
        }

        return $this->shape($groups);
    }

    /**
     * @param  array<string, array{name: string, pages: array<string, array<string, mixed>>, volume: int}>  $groups
     * @return array{silos: list<array<string, mixed>>, totals: array{silos: int, pages: int, sections: int, volume: int}}
     */
    private function shape(array $groups): array
    {
        $silos = [];
        $totalPages = 0;
        $totalSections = 0;
        $totalVolume = 0;

        foreach ($groups as $group) {
            $pages = array_values($group['pages']);
            // Hub first, then biggest-upside pages, then name — deterministic.
            usort($pages, fn (array $a, array $b) => [$a['sort'], -$b['volume'], $a['name']] <=> [$b['sort'], -$a['volume'], $b['name']]);

            $siloVolume = 0;
            $sectionCount = 0;
            foreach ($pages as &$page) {
                usort($page['sections'], fn (array $a, array $b) => [-$a['volume'], $a['name']] <=> [-$b['volume'], $b['name']]);
                $sectionVolume = array_sum(array_column($page['sections'], 'volume'));
                $siloVolume += $page['volume'] + $sectionVolume;
                $sectionCount += count($page['sections']);
                unset($page['id'], $page['sort']);
            }
            unset($page);

            $silos[] = [
                'name' => $group['name'],
                'pages' => $pages,
                'page_count' => count($pages),
                'section_count' => $sectionCount,
                'volume' => $siloVolume,
            ];
            $totalPages += count($pages);
            $totalSections += $sectionCount;
            $totalVolume += $siloVolume;
        }

        // Biggest-opportunity silo first.
        usort($silos, fn (array $a, array $b) => [-$a['volume'], $a['name']] <=> [-$b['volume'], $b['name']]);

        return [
            'silos' => $silos,
            'totals' => [
                'silos' => count($silos),
                'pages' => $totalPages,
                'sections' => $totalSections,
                'volume' => $totalVolume,
            ],
        ];
    }

    /** A page = a pillar, a sub-hub, or an own-page core (each its own page). */
    private function isPage(Spoke $s): bool
    {
        return $s->is_pillar || $s->granularity === SpokeGranularity::OwnPage;
    }

    /**
     * The top-level silo a spoke rolls up to: a demoted sub-hub (and anything under it) rolls
     * up to its parent silo; everything else is its own silo.
     *
     * @param  Collection<string, Spoke>  $pillarsBySilo  keyed by silo name
     * @param  Collection<string, Spoke>  $pillarsById  keyed by spoke id
     */
    private function rootSilo(Spoke $s, Collection $pillarsBySilo, Collection $pillarsById): string
    {
        $pillar = $pillarsBySilo->get((string) $s->silo);
        if ($pillar instanceof Spoke && $pillar->isSubHub() && $pillar->parent_silo_id !== null) {
            $parent = $pillarsById->get($pillar->parent_silo_id);
            if ($parent instanceof Spoke) {
                return (string) $parent->silo;
            }
        }

        return (string) $s->silo;
    }

    private function keyword(Spoke $s): string
    {
        return $s->primary_keyword ?? $s->head_keyword ?? $s->name;
    }

    private function volume(Spoke $s): int
    {
        return (int) ($s->volume ?? 0);
    }

    /**
     * @return array{silos: list<array<string, mixed>>, totals: array{silos: int, pages: int, sections: int, volume: int}}
     */
    private function empty(): array
    {
        return ['silos' => [], 'totals' => ['silos' => 0, 'pages' => 0, 'sections' => 0, 'volume' => 0]];
    }
}
