<?php

namespace App\SiloCreator;

use App\Enums\PageType;
use App\Enums\SiloType;
use App\Models\Silo;
use App\Models\Site;

/**
 * The manual single-silo path: an operator names one silo and we commit it through
 * the SAME SiloCommitter the auto-propose flow uses — so a hand-entered silo is a
 * full citizen (geo-neutral validated, pillar Content stub created + pinned, rule_set
 * seeded), never a raw row §5 can't bucket against. This is the smallest correct
 * front door to silo creation; §4 auto-propose (services/targets → proposed tree) is
 * its own later slice.
 */
class ManualSiloCreator
{
    public function __construct(private readonly SiloCommitter $committer) {}

    /**
     * @param  list<string>  $seedTerms  the rule_set's seed terms — the topical
     *                                   boundary §5 refines with SERP signal.
     *
     * @throws GeoNeutralViolationException when the name or any seed term carries a
     *                                      market/city/state term (the §4 hard rule).
     */
    public function create(Site $site, SiloType $type, string $name, array $seedTerms): Silo
    {
        $ruleSet = new RuleSet(seedTerms: array_values(array_filter(array_map(
            fn (string $term): string => trim($term),
            $seedTerms,
        ))));

        $proposal = new SiloProposal(
            type: $type,
            name: $name,
            ruleSet: $ruleSet,
            source: 'manual',
            pillarPageType: $type === SiloType::ServicePillar ? PageType::Service : PageType::Pillar,
        );

        return $this->committer->commit($site, new SiloProposalSet([$proposal]))->firstOrFail();
    }
}
