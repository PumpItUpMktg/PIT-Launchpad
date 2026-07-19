<?php

namespace App\KeywordGenerator\Derive;

use App\Integrations\Embedding\EmbeddingProvider;
use App\Integrations\Embedding\Vectors;
use App\Models\KeywordCluster;
use App\Models\Scopes\SiteScope;
use App\Models\Service;
use App\Models\Site;

/**
 * The inversion made concrete — maps each Service ONTO the demand-derived structure rather than the
 * structure mirroring the catalog. Each service is pinned (`structure_home_cluster_id`) to the cluster
 * whose head it's most similar to (embedding cosine). A service that matches nothing above the floor is
 * still pinned to its nearest cluster but `structure_home_flagged` for operator review.
 */
final class ServiceStructureMapper
{
    public function __construct(private readonly EmbeddingProvider $embeddings) {}

    /**
     * @param  list<KeywordCluster>  $clusters
     * @return array{mapped: int, flagged: int}
     */
    public function map(Site $site, array $clusters): array
    {
        if ($clusters === []) {
            return ['mapped' => 0, 'flagged' => 0];
        }
        $floor = (float) config('launchpad.keyword_first.service_match_floor', 0.5);

        $clusterVectors = [];
        foreach ($clusters as $cluster) {
            $clusterVectors[$cluster->id] = $this->embeddings->embed((string) ($cluster->head_term ?? $cluster->label ?? ''));
        }

        $mapped = 0;
        $flagged = 0;
        $services = Service::withoutGlobalScope(SiteScope::class)->where('site_id', $site->id)->get();
        foreach ($services as $service) {
            $vector = $this->embeddings->embed((string) $service->name);

            $bestId = null;
            $best = -1.0;
            foreach ($clusters as $cluster) {
                $sim = Vectors::cosine($vector, $clusterVectors[$cluster->id]);
                if ($sim > $best) {
                    $best = $sim;
                    $bestId = $cluster->id;
                }
            }

            $flag = $best < $floor;
            $service->forceFill([
                'structure_home_cluster_id' => $bestId,
                'structure_home_flagged' => $flag,
            ])->save();

            $mapped++;
            if ($flag) {
                $flagged++;
            }
        }

        return ['mapped' => $mapped, 'flagged' => $flagged];
    }
}
