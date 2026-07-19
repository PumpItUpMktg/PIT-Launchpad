<?php

namespace App\KeywordGenerator\Derive;

use App\Enums\KeywordIntent;
use App\Enums\SpokePageType;
use App\Enums\SpokeStatus;
use App\Enums\SpokeTag;
use App\Interview\Expansion\ExpansionPersister;
use App\KeywordGenerator\Cluster\HeadTermSelector;
use App\Models\KeywordCluster;
use App\Models\KeywordCorpus;
use App\Models\Scopes\SiteScope;
use App\Models\SiloBlueprint;
use App\Models\Site;
use App\Models\Spoke;
use Illuminate\Support\Facades\DB;

/**
 * Part 3 — turns the viable demand clusters into the silo tree, as `Spoke` rows on the site's
 * `SiloBlueprint`, in the EXACT shape {@see ExpansionPersister} writes so the
 * unchanged Prune path reads it. Per cluster: a pillar spoke headed by the cluster's head term (the hub
 * is named by what people search, not the catalog), plus a child spoke per remaining member. Tag +
 * intent are set so PrunePlan's existing volume+intent rules route each member (own page / section fold
 * / blog target) unchanged — a commercial/transactional term is `Core` (own page or fold by volume); an
 * informational term is non-core (→ blog target). Granularity is left default; Prune assigns it.
 */
final class StructureDeriver
{
    public function __construct(private readonly HeadTermSelector $heads) {}

    /**
     * @param  list<KeywordCluster>  $clusters  the viable (post-merge) clusters
     */
    public function derive(Site $site, array $clusters): SiloBlueprint
    {
        return DB::transaction(function () use ($site, $clusters): SiloBlueprint {
            $blueprint = SiloBlueprint::withoutGlobalScope(SiteScope::class)->firstOrNew(['site_id' => $site->id]);
            $blueprint->save();

            // Re-derivation replaces the candidate spoke set (same contract as re-expansion).
            Spoke::withoutGlobalScope(SiteScope::class)->where('silo_blueprint_id', $blueprint->id)->delete();

            foreach ($clusters as $cluster) {
                $members = $cluster->members()->withoutGlobalScope(SiteScope::class)->get()->all();
                if ($members === []) {
                    continue;
                }
                $head = $this->heads->select($members) ?? $members[0];
                $siloName = trim((string) ($cluster->label ?? '')) !== '' ? (string) $cluster->label : $head->term;

                // The pillar page — the hub, named by the head term.
                $this->write($blueprint, $site, [
                    'silo' => $siloName,
                    'is_pillar' => true,
                    'name' => $head->term,
                    'page_type' => SpokePageType::Service,
                    'tag' => SpokeTag::Core,
                    'head_keyword' => $head->canonical,
                    'intent' => $this->intent($head),
                    'volume' => $head->volume,
                ]);

                foreach ($members as $member) {
                    if ($member->id === $head->id) {
                        continue;
                    }
                    $informational = $this->intent($member) === KeywordIntent::Informational;
                    $this->write($blueprint, $site, [
                        'silo' => $siloName,
                        'is_pillar' => false,
                        'name' => $member->term,
                        // Non-core informational routes to the blog queue; money terms head their own page.
                        'page_type' => $informational ? SpokePageType::Content : SpokePageType::Service,
                        'tag' => $informational ? SpokeTag::Adjacent : SpokeTag::Core,
                        'head_keyword' => $member->canonical,
                        'intent' => $this->intent($member),
                        'volume' => $member->volume,
                    ]);
                }
            }

            return $blueprint;
        });
    }

    private function intent(KeywordCorpus $term): ?KeywordIntent
    {
        return $term->intent !== null ? KeywordIntent::tryFrom($term->intent) : null;
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    private function write(SiloBlueprint $blueprint, Site $site, array $attributes): void
    {
        Spoke::create(array_merge([
            'silo_blueprint_id' => $blueprint->id,
            'site_id' => $site->id,
            'status' => SpokeStatus::Candidate,
        ], $attributes));
    }
}
