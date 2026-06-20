<?php

namespace App\Build;

use App\Enums\SpokeGranularity;
use App\Enums\SpokeStatus;
use App\Enums\SpokeTag;
use App\Enums\StandardPageType;
use App\Models\CoverageArea;
use App\Models\Scopes\SiteScope;
use App\Models\Site;
use App\Models\Spoke;
use App\Standard\StandardPages;
use Illuminate\Support\Collection;

/**
 * The Page Inventory shown at blueprint confirmation (the bridge between Structure-finalize and
 * Approve): the concrete list of pages the directed-coverage blueprint produces — the same
 * arranged tree from the prune, now as the build inventory. Presentation over the
 * {@see BuildManifestAssembler} Service + Location output (Standard joins at Approve); no new
 * logic, no persistence.
 *
 * Service is grouped by top-level silo: the category hub + its core own-pages + any sub-hub
 * (with its pages beneath), each page carrying its primary keyword and a "covers" list of the
 * folded sections it absorbs. Location is the page_selected towns by tier + the reserve count.
 */
class InventoryPlan
{
    public function __construct(
        private readonly BuildManifestAssembler $assembler,
        private readonly StandardPages $standardPages,
    ) {}

    /**
     * @return array{
     *     counts: array{total: int, foundation: int, service: int, location_now: int, reserve: int},
     *     foundation: list<array{type: string, label: string, kind: string, toggleable: bool, accepted: bool}>,
     *     silos: list<array<string, mixed>>,
     *     tiers: list<array{tier: string, label: string, towns: list<string>}>
     * }
     */
    public function for(Site $site): array
    {
        $preview = $this->assembler->preview($site);
        $foundation = $this->foundation($site);
        // Foundation pages that WILL build = fixed + accepted optionals (the curated selection).
        $foundationCount = count(array_filter($foundation, fn (array $p) => $p['accepted']));
        $serviceCount = count($preview['service']);
        $locationNow = count($preview['location']);
        $reserve = CoverageArea::withoutGlobalScope(SiteScope::class)
            ->where('site_id', $site->id)->where('page_selected', false)->count();

        return [
            'counts' => [
                'total' => $foundationCount + $serviceCount + $locationNow,
                'foundation' => $foundationCount,
                'service' => $serviceCount,
                'location_now' => $locationNow,
                'reserve' => $reserve,
            ],
            'foundation' => $foundation,
            'silos' => $this->serviceTree($site),
            'tiers' => $this->locationTiers($site),
        ];
    }

    /**
     * The Foundation (standard) layer: the fixed core (always built, not toggleable) + the
     * data-gated optionals (toggleable, carrying their accepted state). The optionals' checkboxes
     * curate which standard pages land in the build manifest. Legal pages render muted.
     *
     * @return list<array{type: string, label: string, kind: string, toggleable: bool, accepted: bool}>
     */
    private function foundation(Site $site): array
    {
        $legal = [StandardPageType::Privacy, StandardPageType::Terms];

        $out = [];
        foreach (StandardPageType::fixed() as $type) {
            $out[] = [
                'type' => $type->value,
                'label' => $type->label(),
                'kind' => in_array($type, $legal, true) ? 'legal' : 'core',
                'toggleable' => false,
                'accepted' => true, // fixed core is always built
            ];
        }

        foreach ($this->standardPages->offerable($site) as $row) {
            $out[] = [
                'type' => $row['type']->value,
                'label' => $row['type']->label(),
                'kind' => 'optional',
                'toggleable' => true,
                'accepted' => $row['accepted'],
            ];
        }

        return $out;
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function serviceTree(Site $site): array
    {
        /** @var Collection<int, Spoke> $spokes */
        $spokes = Spoke::withoutGlobalScope(SiteScope::class)->where('site_id', $site->id)->get();
        $visible = $spokes->reject(fn (Spoke $s) => $s->status === SpokeStatus::Skipped || $s->tag === SpokeTag::Fringe);

        $pillarsById = $visible->filter(fn (Spoke $s) => $s->is_pillar)->keyBy('id');
        $coversOf = $visible
            ->filter(fn (Spoke $s) => ! $s->is_pillar && $s->granularity === SpokeGranularity::Folded && $s->fold_into_id !== null)
            ->groupBy('fold_into_id')
            ->map(fn (Collection $g) => $g->map(fn (Spoke $s) => $s->name)->values()->all());

        $coresInSilo = fn (string $silo) => $visible
            ->filter(fn (Spoke $s) => ! $s->is_pillar && $s->granularity === SpokeGranularity::OwnPage && (string) $s->silo === $silo)
            ->sortBy([['volume', 'desc'], ['name', 'asc']])
            ->map(fn (Spoke $s) => [
                'name' => $s->name,
                'type' => 'page',
                'keyword' => $this->keyword($s),
                'covers' => $coversOf->get($s->id, []),
            ])->values()->all();

        $silos = [];
        $topPillars = $visible->filter(fn (Spoke $s) => $s->is_pillar && ! $s->isSubHub())->sortBy('silo');

        foreach ($topPillars as $pillar) {
            $silo = (string) $pillar->silo;
            $hub = ['name' => $pillar->name, 'type' => 'hub', 'keyword' => $this->keyword($pillar), 'covers' => $coversOf->get($pillar->id, [])];
            $pages = $coresInSilo($silo);

            $subhubs = [];
            foreach ($visible->filter(fn (Spoke $s) => $s->isSubHub() && $s->parent_silo_id === $pillar->id)->sortBy('silo') as $sub) {
                $subPages = $coresInSilo((string) $sub->silo);
                $subhubs[] = [
                    'name' => $sub->name,
                    'type' => 'sub-hub',
                    'keyword' => $this->keyword($sub),
                    'covers' => $coversOf->get($sub->id, []),
                    'pages' => $subPages,
                ];
            }

            $count = 1 + count($pages) + collect($subhubs)->sum(fn (array $s) => 1 + count($s['pages']));

            $silos[] = ['name' => $silo, 'hub' => $hub, 'pages' => $pages, 'subhubs' => $subhubs, 'count' => $count];
        }

        return $silos;
    }

    /**
     * @return list<array{tier: string, label: string, towns: list<string>}>
     */
    private function locationTiers(Site $site): array
    {
        $towns = CoverageArea::withoutGlobalScope(SiteScope::class)
            ->where('site_id', $site->id)->where('page_selected', true)
            ->orderByDesc('population')->orderBy('name')->get();

        $labels = ['major' => 'Major', 'large' => 'Large', 'medium' => 'Medium', 'small' => 'Small', 'other' => 'Other'];

        $tiers = [];
        foreach ($labels as $tier => $label) {
            $inTier = $towns->filter(fn (CoverageArea $c) => ($c->size_tier ?? 'other') === $tier);
            if ($inTier->isNotEmpty()) {
                $tiers[] = ['tier' => $tier, 'label' => $label, 'towns' => $inTier->map(fn (CoverageArea $c) => (string) $c->name)->values()->all()];
            }
        }

        return $tiers;
    }

    private function keyword(Spoke $spoke): string
    {
        return $spoke->primary_keyword ?? $spoke->head_keyword ?? $spoke->name;
    }
}
