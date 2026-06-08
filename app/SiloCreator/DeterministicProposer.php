<?php

namespace App\SiloCreator;

use App\Enums\PageType;
use App\Enums\ServiceSiloRole;
use App\Enums\SiloType;
use App\Models\Scopes\SiteScope;
use App\Models\Service;
use App\Models\Site;

/**
 * Deterministic pass: a service_pillar silo per silo_role=pillar service, with
 * its customer problems as candidate clusters. Supporting services become
 * candidate clusters attached to the first pillar (operator refines the
 * assignment, and §5 re-buckets via rule_sets).
 */
class DeterministicProposer
{
    public function __construct(private readonly RuleSetSeeder $seeder) {}

    /**
     * @return list<SiloProposal>
     */
    public function propose(Site $site): array
    {
        $pillars = Service::withoutGlobalScope(SiteScope::class)
            ->where('site_id', $site->id)
            ->where('silo_role', ServiceSiloRole::Pillar->value)
            ->with('problems')
            ->get();

        $supporting = Service::withoutGlobalScope(SiteScope::class)
            ->where('site_id', $site->id)
            ->where('silo_role', ServiceSiloRole::Supporting->value)
            ->with('problems')
            ->get();

        $supportingClusters = [];
        foreach ($supporting as $service) {
            $supportingClusters[] = new ClusterCandidate($service->name, $service->id, null, 'supporting_service');
            foreach ($service->problems as $problem) {
                $supportingClusters[] = new ClusterCandidate($problem->phrase, $service->id, $problem->id, 'service_problem');
            }
        }

        $proposals = [];
        foreach ($pillars as $index => $service) {
            $clusters = [];
            foreach ($service->problems as $problem) {
                $clusters[] = new ClusterCandidate($problem->phrase, $service->id, $problem->id, 'service_problem');
            }

            // Park supporting-service clusters under the first pillar for review.
            if ($index === 0) {
                $clusters = [...$clusters, ...$supportingClusters];
            }

            $proposals[] = new SiloProposal(
                type: SiloType::ServicePillar,
                name: $service->name,
                ruleSet: $this->seeder->forService($service),
                serviceIds: [$service->id],
                clusters: $clusters,
                source: 'deterministic',
                supportCount: count($clusters),
                pillarPageType: PageType::Service,
            );
        }

        return $proposals;
    }
}
