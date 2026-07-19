<?php

namespace App\Build;

use App\Models\FieldProvenance;
use App\Models\Keyword;
use App\Models\KeywordCluster;
use App\Models\Scopes\SiteScope;
use App\Models\Service;
use App\Models\Site;
use App\Models\Spoke;
use Illuminate\Support\Collection;

/**
 * Finds (and removes) the Service rows that structure output contaminated the catalog with — the
 * legacy {@see GuidedEntityProjector} wrote a provenance-less, unenriched Service per pillar / service
 * spoke, name-matched 1:1 to the structure. A row is contamination only when BOTH hold, never either
 * alone:
 *
 *   (a) it carries NO evidence of being a real service — no {@see FieldProvenance} (so not
 *       interview-seeded or operator-confirmed) AND no enrichment at all (no description / scope /
 *       symptoms / process / cost / price / comparison, none of the profitability flags); and
 *   (b) its name exactly matches (case-insensitive) a structure spoke / pillar / keyword / cluster
 *       name for the site.
 *
 * Requiring both spares the two look-alikes that must survive: a genuine manual service (no
 * provenance, but the operator filled in enrichment) and a stated service that happens to share a
 * name with its spoke (has provenance).
 */
class ProjectedServiceCleaner
{
    /** Free-text / scalar enrichment — any one present ⇒ a real, worked-on service. */
    private const ENRICHMENT_SCALAR = [
        'description', 'scope', 'short_description', 'gbp_service_type_id', 'pricing_posture', 'primary_cta_intent',
    ];

    /** JSON enrichment — any non-empty array ⇒ enriched. */
    private const ENRICHMENT_JSON = [
        'symptoms', 'scope_items', 'process_steps', 'cost_factors', 'price_range', 'comparison', 'peak_months',
    ];

    /** Boolean enrichment — any true ⇒ the operator marked it. */
    private const ENRICHMENT_BOOL = [
        'warranty_applicable', 'is_most_profitable', 'is_growth_priority',
    ];

    /**
     * The contaminated Service rows for a site — name-matches structure AND has no provenance/enrichment.
     *
     * @return Collection<int, Service>
     */
    public function contaminated(Site $site): Collection
    {
        $names = $this->structureNames($site);

        return Service::withoutGlobalScope(SiteScope::class)
            ->where('site_id', $site->id)
            ->get()
            ->filter(fn (Service $s): bool => isset($names[mb_strtolower(trim((string) $s->name))])
                && $this->isUnenriched($s)
                && ! $this->hasProvenance($s))
            ->values();
    }

    /** Delete the contaminated rows for a site; returns how many were removed. */
    public function purge(Site $site): int
    {
        $rows = $this->contaminated($site);
        foreach ($rows as $service) {
            $service->delete();
        }

        return $rows->count();
    }

    /** A service with zero enrichment — only its name + silo_role (+ structure mapping, which is not enrichment). */
    public function isUnenriched(Service $service): bool
    {
        foreach (self::ENRICHMENT_SCALAR as $field) {
            if (trim((string) $service->getAttribute($field)) !== '') {
                return false;
            }
        }
        foreach (self::ENRICHMENT_JSON as $field) {
            if (! empty($service->getAttribute($field))) {
                return false;
            }
        }
        foreach (self::ENRICHMENT_BOOL as $field) {
            if ((bool) $service->getAttribute($field) === true) {
                return false;
            }
        }

        return true;
    }

    private function hasProvenance(Service $service): bool
    {
        return FieldProvenance::query()
            ->where('model_type', $service->getMorphClass())
            ->where('model_id', (string) $service->getKey())
            ->exists();
    }

    /**
     * The lowercased set of every structure name for the site — spokes (which carry pillar names too),
     * keyword-cluster head/label, and raw keyword queries — keyed for an O(1) `isset()` lookup.
     *
     * @return array<string, true>
     */
    private function structureNames(Site $site): array
    {
        $spokes = Spoke::withoutGlobalScope(SiteScope::class)->where('site_id', $site->id)->pluck('name');
        $clusters = KeywordCluster::withoutGlobalScope(SiteScope::class)->where('site_id', $site->id)
            ->get(['head_term', 'label'])
            ->flatMap(fn (KeywordCluster $c): array => [$c->head_term, $c->label]);
        $keywords = Keyword::withoutGlobalScope(SiteScope::class)->where('site_id', $site->id)->pluck('query');

        $set = [];
        foreach ($spokes->concat($clusters)->concat($keywords) as $name) {
            $key = mb_strtolower(trim((string) $name));
            if ($key !== '') {
                $set[$key] = true;
            }
        }

        return $set;
    }
}
