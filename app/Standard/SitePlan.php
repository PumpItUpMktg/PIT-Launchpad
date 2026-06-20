<?php

namespace App\Standard;

use App\Client\PagePlan;
use App\Enums\StandardPageType;
use App\Models\CoverageArea;
use App\Models\Scopes\SiteScope;
use App\Models\Site;

/**
 * The complete site plan the client approves at Step 4 — all four page sources in plain
 * language: the fixed standard pages (locked in), the offerable optional standard pages
 * (accept/decline, data-gated), the service pages (from the finalized structure, via the §7c
 * {@see PagePlan}), and the location pages (page_selected towns). The client approves the whole
 * site, not just the service structure.
 */
class SitePlan
{
    public function __construct(
        private readonly StandardPages $standard,
        private readonly PagePlan $servicePlan,
    ) {}

    /**
     * @return array{
     *     fixed: list<array{label: string, source: string}>,
     *     optionals: list<array{type: string, label: string, source: string, accepted: bool}>,
     *     service: list<array<string, mixed>>,
     *     locations: array{count: int, sample: list<string>}
     * }
     */
    public function for(Site $site): array
    {
        $fixed = array_map(
            fn (StandardPageType $t) => ['label' => $t->label(), 'source' => $t->contentSource()],
            StandardPageType::fixed(),
        );

        $optionals = array_map(
            fn (array $row) => [
                'type' => $row['type']->value,
                'label' => $row['type']->label(),
                'source' => $row['type']->contentSource(),
                'accepted' => $row['accepted'],
            ],
            $this->standard->offerable($site),
        );

        $towns = CoverageArea::withoutGlobalScope(SiteScope::class)
            ->where('site_id', $site->id)->where('page_selected', true)
            ->orderByDesc('population')->orderBy('name')->get();

        return [
            'fixed' => $fixed,
            'optionals' => $optionals,
            'service' => $this->servicePlan->for($site)['silos'],
            'locations' => [
                'count' => $towns->count(),
                'sample' => $towns->take(4)->map(fn (CoverageArea $c) => (string) $c->name)->all(),
            ],
        ];
    }
}
