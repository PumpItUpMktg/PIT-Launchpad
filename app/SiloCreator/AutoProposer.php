<?php

namespace App\SiloCreator;

use App\Models\Site;

/**
 * Runs the deterministic and topical passes and returns the combined,
 * reviewable proposal set.
 */
class AutoProposer
{
    public function __construct(
        private readonly DeterministicProposer $deterministic,
        private readonly TopicalClusterer $topical,
    ) {}

    public function propose(Site $site): SiloProposalSet
    {
        return new SiloProposalSet([
            ...$this->deterministic->propose($site),
            ...$this->topical->propose($site),
        ]);
    }
}
